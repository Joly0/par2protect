// Verification Options Functionality

(function(P) {
    // Extend dashboard methods with verification options
    if (P.dashboard) {
        // Show verification options dialog
        P.dashboard.showVerificationOptionsDialog = function(target, force = false, id = null) {
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
        };
        
        // Execute verification with options
        P.dashboard.executeVerification = function(verifyMetadata = false, autoRestoreMetadata = false) {
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
                                            
                                            P.dashboard.startStatusUpdates();
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
                                            
                                            P.dashboard.startStatusUpdates();
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
                        
                        P.dashboard.startStatusUpdates();
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
        };
        
        // Override startVerification to show options dialog
        const originalStartVerification = P.dashboard.startVerification;
        P.dashboard.startVerification = function(target, force = false, id = null) {
            // For 'all' target, we need to prevent the error message from appearing
            if (target === 'all') {
                // Hide any existing error display
                $('#error-display').hide();
                
                // Show verification options dialog
                this.showVerificationOptionsDialog(target, force, id);
                return;
            }

            // For other targets, use the original implementation
            originalStartVerification.call(this, target, force, id);
        };
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
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
                P.dashboard.executeVerification(verifyMetadata, autoRestoreMetadata);
            });
        }
    });
    
})(window.Par2Protect);