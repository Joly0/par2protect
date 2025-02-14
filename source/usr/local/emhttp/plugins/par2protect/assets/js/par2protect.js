const Par2Protect = {
    // Configuration and state
    config: {
        updateInterval: 5000, // Status update interval in ms
        fileCategories: {
            video: {
                extensions: ['mp4', 'mkv', 'avi', 'mov'],
                description: 'Video Files'
            },
            audio: {
                extensions: ['mp3', 'flac', 'wav', 'm4a'],
                description: 'Audio Files'
            },
            images: {
                extensions: ['jpg', 'png', 'raw', 'tiff'],
                description: 'Image Files'
            },
            documents: {
                extensions: ['pdf', 'doc', 'docx', 'txt'],
                description: 'Documents'
            }
        }
    },

    // Initialize dashboard
    initDashboard: function() {
        this.updateStatus();
        this.initializeDialogs();
        this.setupEventListeners();
        // Start periodic updates
        setInterval(() => this.updateStatus(), this.config.updateInterval);
    },

    // Update status information
    updateStatus: function() {
        $.get('/plugins/par2protect/include/exec.php', { action: 'status' }, function(response) {
            if (response.success) {
                Par2Protect.updateStatusDisplay(response.data);
                Par2Protect.updateActivityLog(response.data.recent_activity);
            }
        });
    },

    // Update status display
    updateStatusDisplay: function(data) {
        $('#protected-files').text(data.stats.total_files || '-');
        $('#protected-size').text(data.stats.total_size || '-');
        $('#last-verification').text(data.stats.last_verification || 'Never');
        
        // Update health indicator
        const health = data.stats.health || 'unknown';
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
                        <div>${op.type}: ${op.path}</div>
                        <div>Progress: ${op.progress}%</div>
                        <button onclick="Par2Protect.cancelOperation('${op.id}')">Cancel</button>
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
        if (!activities || !activities.length) {
            return;
        }

        let html = '';
        activities.forEach(activity => {
            html += `
                <tr>
                    <td>${activity.time}</td>
                    <td>${activity.action}</td>
                    <td>${activity.path}</td>
                    <td>${activity.status}</td>
                    <td>${activity.details ? `<i class="fa fa-info-circle" title="${activity.details}"></i>` : ''}</td>
                </tr>
            `;
        });
        $('#activity-log').html(html);
    },

    // Dialog management
    showProtectDialog: function() {
        this.populateFileTypes();
        $('#protect-dialog').show();
    },

    closeDialog: function() {
        $('#protect-dialog').hide();
    },

    // Populate file type checkboxes
    populateFileTypes: function() {
        let html = '';
        Object.entries(this.config.fileCategories).forEach(([key, category]) => {
            html += `
                <label>
                    <input type="checkbox" name="file_types[]" value="${key}">
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

    // Browse folder
    browseFolder: function() {
        // Open Unraid's file browser
        openFileBrowser($('input[name="path"]')[0], 'folder');
    },

    // Start verification
    startVerification: function(target) {
        $.post('/plugins/par2protect/include/exec.php', {
            action: 'verify',
            target: target
        }, function(response) {
            if (response.success) {
                addNotice('Verification started');
            } else {
                swal('Error', response.error, 'error');
            }
        });
    },

    // Show status report
    showStatusReport: function() {
        $.get('/plugins/par2protect/include/exec.php', {
            action: 'report'
        }, function(response) {
            if (response.success) {
                // Use swal to show the report
                swal({
                    title: 'Status Report',
                    text: response.data.report,
                    html: true
                });
            }
        });
    },

    // Cancel operation
    cancelOperation: function(operationId) {
        $.post('/plugins/par2protect/include/exec.php', {
            action: 'cancel',
            operation_id: operationId
        }, function(response) {
            if (response.success) {
                addNotice('Operation cancelled');
            } else {
                swal('Error', response.error, 'error');
            }
        });
    },

    // Setup event listeners
    setupEventListeners: function() {
        // Protection form submission
        $('#protect-form').on('submit', function(e) {
            e.preventDefault();
            $.post('/plugins/par2protect/include/exec.php', $(this).serialize(), function(response) {
                if (response.success) {
                    Par2Protect.closeDialog();
                    addNotice('Protection task started');
                } else {
                    swal('Error', response.error, 'error');
                }
            });
        });

        // Redundancy slider value display
        $('input[name="redundancy"]').on('input', function() {
            $('.redundancy-value').text($(this).val());
        });
    }
};