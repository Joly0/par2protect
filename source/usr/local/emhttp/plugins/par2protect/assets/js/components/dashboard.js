// Dashboard Functionality

(function(P) {
    // Dashboard methods
    const dashboard = {
        // Initialize dashboard
        initDashboard: function() {
            if (P.config.isInitialized) {
                console.log('Dashboard already initialized');
                return;
            }

            console.log('Initializing dashboard...');
            
            try {
                // Initial status check
                this.updateStatus();
                
                // Setup event listeners
                this.setupEventListeners();
                
                P.config.isInitialized = true;
                console.log('Dashboard initialization complete');
            } catch (e) {
                console.error('Failed to initialize dashboard:', e);
                throw e;
            }
        },

        // Start status updates
        startStatusUpdates: function() {
            console.log('Starting status updates');
            if (!P.config.statusCheckTimer) {
                this.updateStatus(); // Immediate update
                // Only start status updates if there are active operations
                $.get('/plugins/par2protect/scripts/tasks/status.php', (response) => {
                    if (response.data && response.data.active_operations && response.data.active_operations.length > 0) {
                        // Update every 5 seconds instead of every second
                        P.config.updateInterval = 5000;
                        // P.config.statusCheckTimer = setInterval(() => this.updateStatus(), P.config.updateInterval);
                    }
                });
            }
        },

        // Stop status updates
        stopStatusUpdates: function() {
            console.log('Stopping status updates');
            if (P.config.statusCheckTimer) {
                clearInterval(P.config.statusCheckTimer);
                P.config.statusCheckTimer = null;
            }
        },

        // Update status information
        updateStatus: function() {
            if (P.config.isLoading) {
                console.log('Status update skipped - already loading');
                return;
            }
            
            console.log('Updating status...');
            P.setLoading(true);

            $.ajax({
                url: '/plugins/par2protect/scripts/tasks/status.php',
                method: 'GET',
                timeout: 10000,
                dataType: 'json',
                success: function(response) {
                    console.log('Status response:', response);
                    
                    if (!response) {
                        console.error('Empty response from status.php');
                        $('#error-display').text('Error: Empty response from server').show();
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
                        if (response.data.active_operations) {
                            if (response.data.active_operations.length > 0) {
                                dashboard.startStatusUpdates();
                                let html = '';
                                response.data.active_operations.forEach(op => {
                                    html += `
                                        <div class="operation-item">
                                            <div>${op.type || 'Processing'}: ${op.path || 'Unknown path'}</div>
                                            <div>Progress: ${op.progress !== null ? op.progress + '%' : 'In progress'}</div>
                                            <button onclick="Par2Protect.dashboard.cancelOperation('${op.pid}')">Cancel</button>
                                        </div>
                                    `;
                                });
                                $('#active-operations').html(html);
                            } else {
                                dashboard.stopStatusUpdates();
                                $('#active-operations').html('<div class="notice">No active operations</div>');
                            }
                        }
                        
                        // Hide any error messages
                        $('#error-display').hide();
                    } else {
                        console.error('Invalid status response:', response);
                        $('#error-display').text('Error: Invalid response from server').show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Status update failed:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    $('#error-display').text('Failed to update status: ' + error).show();
                },
                complete: function() {
                    P.setLoading(false);
                }
            });
        },

        // Update status display
        updateStatusDisplay: function(data) {
            console.log('Updating status display with:', data);
            
            if (!data || !data.stats) {
                console.warn('No data or stats available for status display');
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

            // Update active operations
            if (data.active_operations && data.active_operations.length > 0) {
                let html = '';
                data.active_operations.forEach(op => {
                    html += `
                        <div class="operation-item">
                            <div>${op.type || 'Processing'}: ${op.path || 'Unknown path'}</div>
                            <div>Progress: ${op.progress !== null ? op.progress + '%' : 'In progress'}</div>
                            <button onclick="Par2Protect.dashboard.cancelOperation('${op.pid}')">Cancel</button>
                        </div>
                    `;
                });
                $('#active-operations').html(html);
            } else {
                $('#active-operations').html('<div class="notice">No active operations</div>');
            }
        },

        // Update activity log
        updateActivityLog: function(activities) {
            console.log('Updating activity log with:', activities);
            
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
            this.populateFileTypes();
            $('#protect-dialog').show();
        },

        // Close dialog
        closeDialog: function() {
            $('#protect-dialog').hide();
        },

        // Populate file type checkboxes
        populateFileTypes: function() {
            let html = '';
            Object.entries(P.config.fileCategories).forEach(([key, category]) => {
                html += `
                    <label>
                        <input type="checkbox" name="file_types[]" value="${key}" data-extensions="${category.extensions.join(',')}">
                        ${category.description}
                    </label>
                `;
            });
            $('#file-types').html(html);
        },

        // Update mode options
        updateModeOptions: function(mode) {
            $('#file-type-group').toggle(mode === 'file');
        },

        // Setup event listeners
        setupEventListeners: function() {
            // Protection form submission
            $('#protect-form').on('submit', function(e) {
                e.preventDefault();
                
                const paths = $('#protectedPaths').val();
                if (!paths.trim()) {
                    swal('Error', 'Please select at least one folder to protect', 'error');
                    return;
                }

                // Copy paths to hidden input
                $('#protectedPathsInput').val(paths);

                const selectedTypes = [];
                $('input[name="file_types[]"]:checked').each(function() {
                    const extensions = $(this).data('extensions').split(',');
                    selectedTypes.push(...extensions);
                });

                const formData = {
                    path: paths,
                    mode: $('select[name="mode"]').val(),
                    redundancy: $('input[name="redundancy"]').val(),
                    file_types: selectedTypes
                };
                
                $.ajax({
                    url: '/plugins/par2protect/scripts/tasks/protect.php',
                    method: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        console.log('Protection response:', response);
                        if (response.success) {
                            dashboard.closeDialog();
                            addNotice('Protection task started');
                            dashboard.startStatusUpdates();
                            if (response.stats) {
                                const stats = response.stats;
                                const message = `Files processed: ${stats.processed_files}\nFiles skipped: ${stats.skipped_files}\n${stats.errors.length > 0 ? `\nErrors:\n${stats.errors.join('\n')}` : ''}`;
                                swal({
                                    title: 'Protection Complete',
                                    text: message,
                                    type: stats.errors.length > 0 ? 'warning' : 'success'
                                });
                            }
                        } else {
                            swal('Error', response.error || 'Unknown server error', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Protection failed:', {
                            status: status,
                            error: error,
                            response: xhr.responseText
                        });
                        let errorMessage = 'Failed to start protection task';
                        try {
                            if (xhr.responseText) {
                                const response = JSON.parse(xhr.responseText);
                                if (response.error) {
                                    errorMessage = response.error;
                                }
                            }
                        } catch (e) {
                            console.error('Error parsing error response:', e);
                            errorMessage += ' (Raw response: ' + xhr.responseText + ')';
                        }
                        swal('Error', errorMessage, 'error');
                    }
                });
            });

            // Redundancy slider value update
            $('input[name="redundancy"]').on('input change', function() {
                $('.redundancy-value').text($(this).val());
            });

            // Handle mode change
            $('select[name="mode"]').on('change', function() {
                dashboard.updateModeOptions(this.value);
            });
        },

        // Start verification
        // Show status report
        showStatusReport: function() {
            if (P.config.isLoading) {
                console.log('Status report request skipped - already loading');
                return;
            }
            
            console.log('Requesting status report');
            P.setLoading(true);
            $.ajax({
                url: '/plugins/par2protect/scripts/tasks/report.php',
                method: 'GET',
                dataType: 'json',
                timeout: 30000, // 30 second timeout
                success: function(response) {
                    console.log('Status report response:', response);
                    
                    if (response.success && response.data && response.data.report) {
                        console.log('Showing status report dialog');
                        swal({
                            title: 'Status Report',
                            text: response.data.report,
                            type: 'info',
                            html: true // Allow HTML in the report
                        });
                    } else {
                        let errorMessage = 'Failed to generate status report';
                        if (response.error) {
                            errorMessage = response.error;
                            console.error('Server reported error:', response.error);
                        } else if (!response.success) {
                            errorMessage = 'Server returned unsuccessful response';
                            console.error('Server returned unsuccessful response:', response);
                        } else if (!response.data) {
                            errorMessage = 'No data returned from server';
                            console.error('Missing data in response:', response);
                        } else if (!response.data.report) {
                            errorMessage = 'No report data in server response';
                            console.error('Missing report in data:', response.data);
                        }
                        console.error('Status report failed:', errorMessage);
                        swal('Error', errorMessage, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Status report request failed:', {
                        status: status,
                        error: error,
                        response: xhr.responseText,
                        state: xhr.state(),
                        statusCode: xhr.status,
                        statusText: xhr.statusText
                    });

                    let errorMessage = 'Failed to generate status report';
                    let errorDetails = '';
                    let technicalDetails = '';

                    try {
                        if (xhr.responseText) {
                            // Check if response is HTML (error page)
                            if (xhr.responseText.trim().startsWith('<')) {
                                // Extract error message from PHP error page
                                const errorMatch = xhr.responseText.match(/<b>Fatal error<\/b>:\s*(.*?)\s*in/);
                                if (errorMatch) {
                                    errorMessage = errorMatch[1];
                                    console.error('PHP Error:', errorMessage);
                                }
                                technicalDetails = '\n\nTechnical Details: ' + xhr.responseText.replace(/<[^>]*>/g, '');
                            } else {
                                // Try parsing as JSON
                                const response = JSON.parse(xhr.responseText);
                                if (response.error) {
                                    errorMessage = response.error;
                                    console.error('Server error:', response.error);
                                }
                            }
                        }

                        // Add more context based on the type of error
                        if (status === 'timeout') {
                            errorMessage = 'Request timed out while generating status report';
                        } else if (status === 'parsererror') {
                            errorMessage = 'Failed to parse server response';
                            if (xhr.responseText) {
                                technicalDetails = '\n\nResponse: ' + xhr.responseText.substring(0, 200) + '...';
                            }
                        } else if (xhr.status === 500) {
                            errorMessage = 'Internal server error while generating status report';
                        } else if (xhr.status === 404) {
                            errorMessage = 'Status report endpoint not found';
                        }

                        if (xhr.status !== 200) {
                            errorDetails = '\n\nHTTP Status: ' + xhr.status + ' ' + xhr.statusText;
                        }
                    } catch (e) {
                        console.error('Error parsing error response:', e);
                        errorDetails += '\n\nParse Error: ' + e.message;
                    }

                    swal({
                        title: 'Error',
                        text: errorMessage + errorDetails + technicalDetails,
                        type: 'error',
                        html: true
                    });
                },
                complete: function() {
                    P.setLoading(false);
                }
            });
        },

        startVerification: function(target, force = false) {
            if (P.config.isLoading) return;
            
            P.setLoading(true);
            $.ajax({
                url: '/plugins/par2protect/scripts/tasks/verify.php',
                method: 'POST',
                data: {
                    target: target,
                    force: force
                },
                timeout: 30000,
                success: function(response) {
                    console.log('Verification response:', response);
                    if (response.success) {
                        // Handle immediate completion
                        if (response.stats) {
                            const stats = response.stats;
                            swal({
                                title: 'Verification Complete',
                                text: `Files processed: ${stats.total_files}\nVerified: ${stats.verified_files}\nFailed: ${stats.failed_files}`,
                                type: stats.failed_files > 0 ? 'warning' : 'success'
                            });
                        }
                        // Handle task start
                        else if (response.tasks) {
                            swal({
                                title: 'Verification Started',
                                text: `Started verification of ${response.tasks.length} items`,
                                type: 'info'
                            });
                        }
                        // Generic success
                        else {
                            swal({
                                title: 'Verification Started',
                                text: response.message || 'Verification task started successfully',
                                type: 'info'
                            });
                        }
                        addNotice('Verification task started');
                        dashboard.startStatusUpdates();
                    } else {
                        swal('Error', response.error || 'Failed to start verification', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Verification failed:', {
                        status: status,
                        error: error,
                        response: xhr.responseText,
                        state: xhr.state(),
                        statusCode: xhr.status,
                        statusText: xhr.statusText
                    });

                    let errorMessage = 'Failed to start verification';
                    let errorDetails = '';
                    let technicalDetails = '';

                    try {
                        if (xhr.responseText) {
                            // Check if response is HTML (error page)
                            if (xhr.responseText.trim().startsWith('<')) {
                                // Extract error message from PHP error page
                                const errorMatch = xhr.responseText.match(/<b>Fatal error<\/b>:\s*(.*?)\s*in/);
                                if (errorMatch) {
                                    errorMessage = errorMatch[1];
                                    console.error('PHP Error:', errorMessage);
                                }
                                technicalDetails = '\n\nTechnical Details: ' + xhr.responseText.replace(/<[^>]*>/g, '');
                            } else {
                                // Try parsing as JSON
                                const response = JSON.parse(xhr.responseText);
                                if (response.error) {
                                    errorMessage = response.error;
                                    if (response.context) {
                                        errorDetails = '\n\nContext: ' + JSON.stringify(response.context, null, 2);
                                    }
                                }
                            }
                        }

                        // Add more context based on the type of error
                        if (status === 'timeout') {
                            errorMessage = 'Request timed out while starting verification';
                        } else if (status === 'parsererror') {
                            errorMessage = 'Failed to parse server response';
                            if (xhr.responseText) {
                                technicalDetails = '\n\nResponse: ' + xhr.responseText.substring(0, 200) + '...';
                            }
                        } else if (xhr.status === 500) {
                            errorMessage = 'Internal server error while starting verification';
                        }

                        if (xhr.status !== 200) {
                            errorDetails += '\n\nHTTP Status: ' + xhr.status + ' ' + xhr.statusText;
                        }
                    } catch (e) {
                        console.error('Error parsing error response:', e);
                        errorDetails += '\n\nParse Error: ' + e.message;
                    }

                    swal({
                        title: 'Error',
                        text: errorMessage + errorDetails + technicalDetails,
                        type: 'error',
                        html: true
                    });
                },
                complete: function() {
                    P.setLoading(false);
                }
            });
        }
    };

    // Add dashboard methods to Par2Protect
    P.dashboard = dashboard;

    // Initialize when document is ready
    $(document).ready(function() {
        if (document.querySelector('.par2-dashboard')) {
            P.dashboard.initDashboard();
        }
    });

})(window.Par2Protect);