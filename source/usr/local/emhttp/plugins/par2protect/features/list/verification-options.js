// Verification Options Functionality for List View

(function(P) {
    // Extend list methods with verification options
    if (P.list) {
        // Store original verifySelected method
        const originalVerifySelected = P.list.verifySelected;
        
        // Override verifySelected to show options dialog
        P.list.verifySelected = function(singlePath, forceVerify = false) {
            // Show verification options dialog
            this.showVerificationOptionsDialog(singlePath, forceVerify);
        };
        
        // Show verification options dialog
        P.list.showVerificationOptionsDialog = function(singlePath, forceVerify = false) {
            // Store verification parameters for later use
            this.verificationPath = singlePath;
            this.verificationForce = forceVerify;
            
            // Reset form
            $('#verify-metadata-checkbox').prop('checked', false);
            $('#auto-restore-metadata-checkbox').prop('checked', false);
            $('#auto-restore-group').hide();
            
            // Show dialog
            $('#verification-options-dialog').show();
            
            // Log that dialog is shown
            P.logger.debug('Verification options dialog shown', { singlePath, forceVerify });
        };
        
        // Execute verification with options
        P.list.executeVerification = function(verifyMetadata = false, autoRestoreMetadata = false) {
            const singlePath = this.verificationPath;
            const forceVerify = this.verificationForce;
            
            let paths = [];
            let id = null, $button = null;

            if (singlePath) {
                P.logger.debug('Single path mode');
                paths.push(singlePath);
                
                // First try to find by path only to get the ID
                $button = $('.verify-btn[data-path="' + singlePath + '"]').first();
                if ($button.length > 0) {
                    id = $button.data('id');
                    
                    // Now use our helper function to find the specific button
                    $button = this.findButtonByIdAndPath('verify-btn', id, singlePath);
                    
                    P.logger.debug('Found verify button using helper', {
                        path: singlePath,
                        id: id,
                        uniqueId: $button.data('unique'),
                        buttonFound: $button.length > 0
                    });
                }
            } else {
                P.logger.debug('Multiple selection mode');
                // For multiple selection, we'll collect all items with their IDs
                let selectedItems = [];
                $('.select-item:checked').each(function() {
                    const $row = $(this).closest('tr');
                    const id = $row.find('td:nth-child(2)').text();
                    const path = $row.find('td:nth-child(3)').text();
                    paths.push(path);
                    selectedItems.push({ id: id, path: path });
                });
                
                // Store the selected items for later use
                this.selectedItems = selectedItems;
            }
    
            if (paths.length === 0) {
                P.logger.debug('No paths selected');
                swal('Error', 'Please select items to verify', 'error');
                return;
            }
    
            P.setLoading(true);
    
            // Process each path individually
            if (singlePath && id) {
                // Single item with known ID
                this.verifyItemWithMetadata(singlePath, id, forceVerify, verifyMetadata, autoRestoreMetadata);
            } else if (this.selectedItems && this.selectedItems.length > 0) {
                // Multiple items with IDs
                this.selectedItems.forEach(item => {
                    this.verifyItemWithMetadata(item.path, item.id, forceVerify, verifyMetadata, autoRestoreMetadata);
                });
            } else {
                // Fallback to old behavior if IDs are not available
                paths.forEach(path => {
                    this.verifyItemWithMetadata(path, null, forceVerify, verifyMetadata, autoRestoreMetadata);
                });
            }
        };
        
        // Verify a single item with metadata options
        P.list.verifyItemWithMetadata = function(path, id, forceVerify = false, verifyMetadata = false, autoRestoreMetadata = false) {
            P.setLoading(true);
            
            // Prepare parameters - always force verification
            const params = { 
                path: path, 
                force: forceVerify,
                verify_metadata: verifyMetadata,
                auto_restore_metadata: autoRestoreMetadata
            };
            
            if (id) {
                params.id = id;
            }
            
            // Use queue manager to add verification to queue
            P.queueManager.addToQueue(
                'verify',
                params,
                function(response) {
                    // Success callback
                    P.logger.debug('Verification added to queue', { 
                        path: path, 
                        id: id,
                        verify_metadata: verifyMetadata,
                        auto_restore_metadata: autoRestoreMetadata
                    });
                    
                    swal({
                        title: 'Verification Started',
                        text: 'Verification task has been added to the queue',
                        type: 'info'
                    });
                    
                    // Uncheck all checkboxes
                    $('.select-item').prop('checked', false);
                    $('#selectAll').prop('checked', false);
                    P.list.updateSelectedButtons();
                    
                    // Refresh the list to show updated status
                    P.list.refreshProtectedList(true);
                    P.setLoading(false);
                },
                function(error) {
                    // Error callback
                    P.logger.error('Failed to add verification to queue:', { 
                        path: path, 
                        id: id, 
                        error: error,
                        verify_metadata: verifyMetadata,
                        auto_restore_metadata: autoRestoreMetadata
                    });
                    
                    swal('Error', 'Failed to add verification task to queue: ' + error, 'error');
                    
                    // Uncheck all checkboxes
                    $('.select-item').prop('checked', false);
                    $('#selectAll').prop('checked', false);
                    P.list.updateSelectedButtons();
                    
                    // Refresh the list
                    P.list.refreshProtectedList(true);
                    P.setLoading(false);
                }
            );
        };
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        if (document.querySelector('#verification-options-dialog')) {
            // Add event listeners for the dialog
            $('#verify-metadata-checkbox').on('change', function() {
                $('#auto-restore-group').toggle(this.checked);
            });
            
            $('#verification-options-dialog .cancel-btn').on('click', function() {
                // Hide dialog
                $('#verification-options-dialog').hide();
                P.logger.debug('Verification options dialog cancelled');
            });
            
            $('#verification-options-form').on('submit', function(e) {
                e.preventDefault();
                
                // Get options
                const verifyMetadata = $('#verify-metadata-checkbox').is(':checked');
                const autoRestoreMetadata = $('#auto-restore-metadata-checkbox').is(':checked');
                
                // Hide and dialog
                $('#verification-options-dialog').hide();
                
                P.logger.debug('Verification options form submitted', { verifyMetadata, autoRestoreMetadata });
                
                // Start verification with options
                P.list.executeVerification(verifyMetadata, autoRestoreMetadata);
            });
        }
    });
    
})(window.Par2Protect);