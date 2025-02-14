// Protected Files List Functionality

(function(P) {
    // List methods
    const list = {
        // Helper function to find a button by ID and path
        findButtonByIdAndPath: function(buttonClass, id, path) {
            // First try to find by unique identifier
            const uniqueId = id + '-' + encodeURIComponent(path);
            let $button = $('.' + buttonClass + '[data-unique="' + uniqueId + '"]');
            
            // If not found, fall back to finding by path and then filtering by ID
            if ($button.length === 0) {
                const $buttons = $('.' + buttonClass + '[data-path="' + path + '"]');
                if ($buttons.length > 1) {
                    // Multiple buttons with same path, filter by ID
                    $buttons.each(function() {
                        if ($(this).data('id') == id) {
                            $button = $(this);
                            return false; // break the loop
                        }
                    });
                } else if ($buttons.length === 1) {
                    // Only one button with this path
                    $button = $buttons;
                }
            }
            
            return $button;
        },
        
        // Refresh protected files list
        refreshProtectedList: function(showLoading = true) {
            // Only show loading overlay for manual refreshes
            if (showLoading) {
                P.setLoading(true);
            }

            // Store the checked state of checkboxes before refreshing
            const checkedPaths = [];
            $('.select-item:checked').each(function() {
                const path = $(this).closest('tr').find('td:nth-child(3)').text();
                checkedPaths.push(path);
            });

            $.ajax({
                url: '/plugins/par2protect/api/v1/index.php?endpoint=protection',
                method: 'GET',
                dataType: 'json',
                // Add cache-busting parameter to ensure fresh data
                cache: false,
                data: { _nocache: new Date().getTime() },
                success: function(response) {
                    
                    // Check if the response has the refresh_list flag
                    if (response.success && P.handleDirectOperationResponse) {
                        P.handleDirectOperationResponse(response);
                    }
                    
                    if (response.success) {
                        const items = response.data || [];
                        
                        // Add diagnostic logging for protected items
                        P.logger.debug("DIAGNOSTIC: Protected items received for display", {
                            'items_count': items.length,
                            'first_few_items': items.slice(0, 3).map(item => ({
                                id: item.id,
                                path: item.path,
                                mode: item.mode,
                                file_types: item.file_types
                            }))
                        });
                        
                        const $list = $('#protected-files-list');
                        $list.empty(); // Clear existing items

                        if (items.length === 0) {
                            $list.append('<tr><td colspan="8" class="notice">No protected files found</td></tr>');
                        } else {
                            items.forEach(item => {
                                // Add diagnostic logging for individual file types
                                if (item.mode === 'directory' && item.file_types) {
                                    P.logger.debug("DIAGNOSTIC: Directory with file types", {
                                        'id': item.id,
                                        'path': item.path,
                                        'file_types': item.file_types
                                    });
                                }
                                
                                // Add diagnostic logging for Individual Files mode
                                if (item.mode.includes('Individual Files')) {
                                    P.logger.debug("DIAGNOSTIC: Individual Files mode item", {
                                        'id': item.id,
                                        'path': item.path,
                                        'mode': item.mode,
                                        'file_types': item.file_types,
                                        'exact_match': item.mode === 'Individual Files',
                                        'includes_match': item.mode.includes('Individual Files')
                                    });
                                }
                                
                                // Check if this item was previously checked
                                const isChecked = checkedPaths.includes(item.path) ? 'checked' : '';
                                
                                // Add info icon for file types
                                let fileTypeInfo = '';
                                let fileTypeDetails = '';
                                
                                if ((item.mode === 'file' && item.parent_dir) || 
                                    (item.mode.includes('Individual Files') && item.file_types) ||
                                    (item.mode === 'directory' && item.file_types)) {
                                    
                                    // Prepare details for tooltip
                                    if (item.mode === 'file' && item.parent_dir) {
                                        // This is an individual file
                                        const extension = item.path.split('.').pop().toLowerCase();
                                        fileTypeDetails = `File type: ${extension}`;
                                    } else if ((item.mode.includes('Individual Files') || item.mode === 'directory') && item.file_types) {
                                        // This is a directory with file types
                                        fileTypeDetails = `Protected file types: ${item.file_types.join(', ')}`;
                                        
                                        // If this is Individual Files mode, try to get the count of protected files
                                        if (item.mode.includes('Individual Files')) {
                                            // We don't have the count directly, but we can indicate it's protecting individual files
                                            fileTypeDetails += '<br>Protecting individual files matching these types';
                                        }
                                    }
                                    
                                    // Add info icon with tooltip
                                    fileTypeInfo = `<i class="fa fa-info-circle file-type-info" data-details="${P.escapeHtml(fileTypeDetails)}"></i>`;
                                }
                                
                                $list.append(`
                                    <tr>
                                        <td><input type="checkbox" class="select-item" ${isChecked}></td>
                                        <td style="display:none;">${item.id}</td>
                                        <td>${item.path}</td>
                                        <td>${item.mode}${fileTypeInfo ? ' ' + fileTypeInfo : ''}</td>
                                        <td>${item.redundancy}%</td>
                                        <td>${item.size_formatted || item.size}</td>
                                        <td class="status-cell" data-status="${item.last_status || 'Unknown'}" data-details="${item.last_details ? P.escapeHtml(item.last_details) : ''}">
                                            <span>${item.last_status || 'Unknown'}</span>
                                            ${item.last_status === 'ERROR' || item.last_status === 'Error' || item.last_status === 'REPAIR_FAILED' || item.last_status === 'MISSING' || item.last_status === 'DAMAGED' || item.last_status === 'METADATA_ISSUES' ?
                                                '<i class="fa fa-info-circle error-info-icon"></i>' : ''}
                                        </td>
                                        <td>${item.protected_date || 'Unknown'}</td>
                                        <td>${item.last_verified || 'Never'}</td>
                                        <td>
                                            <button class="verify-btn" data-path="${item.path}" data-id="${item.id}" data-unique="${item.id}-${encodeURIComponent(item.path)}">Verify</button>
                                            ${item.last_status === 'MISSING' || item.last_status === 'DAMAGED' || item.last_status === 'ERROR' || item.last_status === 'Error' ?
                                                `<button class="repair-btn" data-path="${item.path}" data-id="${item.id}" data-unique="${item.id}-${encodeURIComponent(item.path)}">Repair</button>
                                                <button class="reprotect-btn" data-path="${item.path}" data-id="${item.id}" data-unique="${item.id}-${encodeURIComponent(item.path)}">Re-protect</button>` : ''}
                                            <button class="remove-btn" data-path="${item.path}" data-id="${item.id}" data-unique="${item.id}-${encodeURIComponent(item.path)}">Remove</button>
                                        </td>
                                    </tr>
                                `);
                            });
                            
                            // Update the "select all" checkbox state
                            if (checkedPaths.length > 0) {
                                const allChecked = $('.select-item').length === checkedPaths.length;
                                $('#selectAll').prop('checked', allChecked);
                            }
                            
                            // Update selected buttons state
                            list.updateSelectedButtons();
                        }
                    } else {
                        P.logger.error('Error in response:', { error: response.error });
                        $('#error-display').text('Error: ' + response.error).show();
                    }
                },
                error: function(xhr, status, error) {
                    P.logger.error('Failed to refresh protected files list:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    $('#error-display').text('Failed to refresh protected files list: ' + error).show();
                },
                complete: function() {
                    // Only remove loading overlay if it was shown
                    if (showLoading) {
                        P.setLoading(false);
                    }
                }
            });
        },

        // Filter list by status
        filterByStatus: function(status) {
            const rows = $('#protected-files-list tr');
            P.setLoading(true);
            
            if (status === 'all') {
                rows.show();
            } else {
                rows.each(function() {
                    // Use data-status attribute instead of text content for more reliable filtering
                    const rowStatus = $(this).find('td.status-cell').attr('data-status').toLowerCase();
                    
                    // Special case: "protected" filter should also show "verified" items
                    if (status.toLowerCase() === 'protected') {
                        $(this).toggle(rowStatus === 'protected' || rowStatus === 'verified' || rowStatus === 'repaired');
                    } else {
                        $(this).toggle(rowStatus === status.toLowerCase());
                    }
                });
            }
            
            P.setLoading(false);
        },

        // Filter list by mode
        filterByMode: function(mode) {
            const rows = $('#protected-files-list tr');
            
            if (mode === 'all') {
                rows.show();
            } else {
                rows.each(function() {
                    const rowMode = $(this).find('td:nth-child(4)').text().toLowerCase();
                    $(this).toggle(rowMode === mode.toLowerCase());
                });
            }
        },

        // Toggle all checkboxes
        toggleSelectAll: function(checkbox) {
            $('.select-item').prop('checked', checkbox.checked);
            list.updateSelectedButtons();
        },

        // Update selected buttons state
        updateSelectedButtons: function() {
            const hasSelected = $('.select-item:checked').length > 0;
            $('#removeSelectedBtn, #verifySelectedBtn, #reprotectSelectedBtn').prop('disabled', !hasSelected);
        },
        
        // Re-protect selected items
        reprotectSelected: function() {
            let paths = [];
            // Store both paths and IDs
            let selectedItems = [];
            
            $('.select-item:checked').each(function() {
                const $row = $(this).closest('tr');
                const id = $row.find('td:nth-child(2)').text();
                const path = $row.find('td:nth-child(3)').text();
                paths.push(path);
                selectedItems.push({ id: id, path: path });
            });

            if (paths.length === 0) {
                swal('Error', 'Please select items to re-protect', 'error');
                return;
            }

            swal({
                title: 'Confirm Re-protection',
                text: 'This will remove the existing protection and create new protection files for ' + paths.length + ' item(s). Continue?',
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, re-protect',
                cancelButtonText: 'Cancel'
            }, function(confirmed) {
                if (confirmed) {
                    P.setLoading(true);
                    
                    // Use default redundancy for all paths
                    const defaultRedundancy = P.settings?.default_redundancy || 10;
                    
                    // Track how many operations we've added
                    let operationsAdded = 0;
                    let operationsFailed = 0;
                    
                    // Process each path individually
                    selectedItems.forEach(function(item) {
                        
                        // Step 1: Remove protection
                        P.queueManager.addToQueue(
                            'remove',
                            { path: item.path, id: item.id },
                            function(removeResponse) {
                                
                                // Step 2: Add protection after a delay with default redundancy
                                const path = item.path;
                                setTimeout(function() {
                                    P.queueManager.addToQueue(
                                        'protect',
                                        {
                                            path: path,
                                            redundancy: defaultRedundancy
                                        },
                                        function(protectResponse) {
                                            operationsAdded++;
                                            
                                            // Check if all operations have been processed
                                            if (operationsAdded + operationsFailed === paths.length) {
                                                // All operations have been processed
                                                if (operationsFailed === 0) {
                                                    // All operations were successful
                                                    swal({
                                                        title: 'Re-protection Started',
                                                        text: paths.length > 1
                                                            ? paths.length + ' re-protection tasks have been added to the queue'
                                                            : 'Re-protection task has been added to the queue',
                                                        type: 'info'
                                                    });
                                                } else {
                                                    // Some operations failed
                                                    addNotice(operationsAdded + ' re-protection tasks added to queue, ' + operationsFailed + ' failed');
                                                    
                                                    swal({
                                                        title: 'Re-protection Partially Started',
                                                        text: operationsAdded + ' re-protection tasks added to queue, ' + operationsFailed + ' failed',
                                                        type: 'warning'
                                                    });
                                                }
                                                
                                                // Uncheck all checkboxes and force refresh the list
                                                $('.select-item').prop('checked', false);
                                                $('#selectAll').prop('checked', false);
                                                list.updateSelectedButtons();
                                                
                                                // Force a complete refresh to ensure checkbox state is reset
                                                setTimeout(function() {
                                                    list.refreshProtectedList(true);
                                                }, 500);
                                            }
                                        },
                                        function(error) {
                                            P.logger.error('Failed to add protect to queue during re-protection:', { error });
                                            operationsFailed++;
                                            
                                            // Check if all operations have been processed
                                            if (operationsAdded + operationsFailed === paths.length) {
                                                // All operations have been processed
                                                if (operationsFailed === paths.length) {
                                                    // All operations failed
                                                    swal('Error', 'Failed to add re-protection tasks to queue', 'error');
                                                } else {
                                                    // Some operations failed
                                                    addNotice(operationsAdded + ' re-protection tasks added to queue, ' + operationsFailed + ' failed');
                                                    
                                                    swal({
                                                        title: 'Re-protection Partially Started',
                                                        text: operationsAdded + ' re-protection tasks added to queue, ' + operationsFailed + ' failed',
                                                        type: 'warning'
                                                    });
                                                }
                                                
                                                // Uncheck all checkboxes and force refresh the list
                                                $('.select-item').prop('checked', false);
                                                $('#selectAll').prop('checked', false);
                                                list.updateSelectedButtons();
                                                
                                                // Force a complete refresh to ensure checkbox state is reset
                                                setTimeout(function() {
                                                    list.refreshProtectedList(true);
                                                }, 500);
                                            }
                                        }
                                    );
                                }, 2000); // 2 second delay to ensure remove operation has time to process
                            },
                            function(error) {
                                P.logger.error('Failed to add remove to queue during re-protection:', { error });
                                operationsFailed++;
                                
                                // Check if all operations have been processed
                                if (operationsAdded + operationsFailed === paths.length) {
                                    // All operations have been processed
                                    swal('Error', 'Failed to add re-protection tasks to queue', 'error');
                                    
                                    // Uncheck all checkboxes and force refresh the list
                                    $('.select-item').prop('checked', false);
                                    $('#selectAll').prop('checked', false);
                                    list.updateSelectedButtons();
                                    
                                    // Force a complete refresh to ensure checkbox state is reset
                                    setTimeout(function() {
                                        list.refreshProtectedList(true);
                                    }, 500);
                                }
                            }
                        );
                    });
                }
            });
        },

        // Verify selected items
        verifySelected: function(singlePath, forceVerify = false) {
            // Use the verification options dialog instead of direct verification
            // This will show the dialog with options before proceeding
            if (typeof this.showVerificationOptionsDialog === 'function') {
                this.showVerificationOptionsDialog(singlePath, forceVerify);
                return;
            }
            
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
    
            // Track how many operations we've added
            let operationsAdded = 0;
            let operationsFailed = 0;
            
            // Process each path individually
            if (singlePath && id) {
                // Single item with known ID
                this.verifyItem(singlePath, id, forceVerify);
            } else if (this.selectedItems && this.selectedItems.length > 0) {
                // Multiple items with IDs
                this.selectedItems.forEach(item => {
                    this.verifyItem(item.path, item.id, forceVerify);
                });
            } else {
                // Fallback to old behavior if IDs are not available
                paths.forEach(path => {
                    this.verifyItem(path, null, forceVerify);
                });
            }
        },
        
        // Verify a single item
        verifyItem: function(path, id, forceVerify = false) {
            P.setLoading(true);
            
            // Prepare parameters - always force verification
            const params = { path: path, force: forceVerify };
            if (id) {
                params.id = id;
            }
            
                // Use queue manager to add verification to queue
                P.queueManager.addToQueue(
                    'verify',
                    params,
                    function(response) {
                        // Success callback
                        P.logger.debug('Verification added to queue', { path: path, id: id });
                        
                        swal({
                            title: 'Verification Started',
                            text: 'Verification task has been added to the queue',
                            type: 'info'
                        });
                        
                        // Uncheck all checkboxes
                        $('.select-item').prop('checked', false);
                        $('#selectAll').prop('checked', false);
                        list.updateSelectedButtons();
                        
                        // Refresh the list to show updated status
                        list.refreshProtectedList(true);
                        P.setLoading(false);
                    },
                    function(error) {
                        // Error callback
                        P.logger.error('Failed to add verification to queue:', { path: path, id: id, error: error });
                        
                        swal('Error', 'Failed to add verification task to queue: ' + error, 'error');
                        
                        // Uncheck all checkboxes
                        $('.select-item').prop('checked', false);
                        $('#selectAll').prop('checked', false);
                        list.updateSelectedButtons();
                        
                        // Refresh the list
                        list.refreshProtectedList(true);
                        P.setLoading(false);
                    }
                );
        },

        // Remove selected protections
        removeSelectedProtections: function(singlePath) {
            let paths = [];
            let id = null;
            
            if (singlePath) {
                paths.push(singlePath);
                
                // Try to find the ID from any remove button with this path
                const $button = $('.remove-btn[data-path="' + singlePath + '"]').first();
                if ($button.length > 0) {
                    id = $button.data('id');
                    
                    P.logger.debug('Found remove button for path', {
                        path: singlePath,
                        id: id,
                        uniqueId: $button.data('unique')
                    });
                }
            } else {
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
                swal('Error', 'Please select items to remove', 'error');
                return;
            }

            swal({
                title: 'Confirm Removal',
                text: 'Are you sure you want to remove protection for the selected items?',
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, remove protection',
                cancelButtonText: 'Cancel'
            }, function(confirmed) {
                if (confirmed) {
                    P.setLoading(true);
                    
                    // Track how many operations we've added
                    let operationsAdded = 0;
                    let operationsFailed = 0;
                    
                    // Process each path individually
                    if (singlePath && id) {
                        // Single item with known ID
                        list.removeItem(singlePath, id);
                    } else if (list.selectedItems && list.selectedItems.length > 0) {
                        // Multiple items with IDs
                        list.selectedItems.forEach(item => {
                            list.removeItem(item.path, item.id);
                        });
                    } else {
                        // Fallback to old behavior if IDs are not available
                        paths.forEach(path => {
                            list.removeItem(path, null);
                        });
                    }
                }
            });
        },
        
        // Remove a single item
        removeItem: function(path, id) {
            P.setLoading(true);
            
            // Prepare parameters
            const params = { path: path };
            if (id) {
                params.id = id;
            }
            
                        // Use queue manager to add removal to queue
                        P.queueManager.addToQueue(
                            'remove',
                            params,
                            function(response) {
                                // Success callback
                                P.logger.debug('Removal added to queue', { path: path, id: id });
                                
                                // Uncheck all checkboxes
                                $('.select-item').prop('checked', false);
                                $('#selectAll').prop('checked', false);
                                list.updateSelectedButtons();
                                
                                // Refresh the list
                                list.refreshProtectedList(true);
                                P.setLoading(false);
                            },
                            function(error) {
                                // Error callback
                                P.logger.error('Failed to add removal to queue:', { path: path, id: id, error: error });
                                
                                swal('Error', 'Failed to add removal task to queue: ' + error, 'error');
                                
                                // Refresh the list
                                list.refreshProtectedList(true);
                                P.setLoading(false);
                            }
                        );
        },
        
        // Repair a protected item
        repairItem: function(path) {
            // Find the ID from the button that was clicked
            const $button = $('.repair-btn[data-path="' + path + '"]').first();
            let id = null;
            
            if ($button.length > 0) {
                id = $button.data('id');
                
                P.logger.debug('Found repair button for path', {
                    path: path,
                    id: id,
                    uniqueId: $button.data('unique')
                });
            }
            
            P.setLoading(true);
            
            // Prepare parameters
            const params = { path: path };
            if (id) {
                params.id = id;
            }
            
            // Use queue manager to add repair to queue
            P.queueManager.addToQueue(
                'repair',
                params,
                function(response) {
                    swal({
                        title: 'Repair Started',
                        text: 'Repair task has been added to the queue',
                        type: 'info'
                    });
                    
                    // Refresh the list to show updated status - show loading for manual refresh
                    list.refreshProtectedList(true);
                    P.setLoading(false);
                },
                function(error) {
                    P.logger.error('Failed to add repair to queue:', { error });
                    swal('Error', error, 'error');
                    P.setLoading(false);
                }
            );
        },
        
        // Re-protect a protected item
        reprotectItem: function(path) {
            // Find the ID from the button that was clicked
            const $button = $('.reprotect-btn[data-path="' + path + '"]').first();
            let id = null;
            let $row = null;
            
            if ($button.length > 0) {
                id = $button.data('id');
                $row = $button.closest('tr');
                
                P.logger.debug('Found reprotect button for path', {
                    path: path,
                    id: id,
                    uniqueId: $button.data('unique')
                });
            } else {
                P.logger.error('Could not find reprotect button for path', { path: path });
                swal('Error', 'Could not find item to re-protect', 'error');
                return;
            }
            
            // Get the current redundancy level from the row
            const currentRedundancyText = $row.find('td:nth-child(5)').text();
            const currentRedundancy = parseInt(currentRedundancyText.replace('%', ''));
            
            P.setLoading(true);
            
            // Get default redundancy as fallback
            const defaultRedundancy = P.settings?.default_redundancy || 10;
            
            // Use current redundancy if available, otherwise use default
            const redundancy = !isNaN(currentRedundancy) ? currentRedundancy : defaultRedundancy;
            
            P.logger.debug("Re-protect redundancy", {
                'path': path,
                'id': id,
                'current_redundancy_text': currentRedundancyText,
                'current_redundancy': currentRedundancy,
                'default_redundancy': defaultRedundancy,
                'using_redundancy': redundancy
            });
            
            // Show confirmation dialog with default redundancy
            swal({
                title: 'Confirm Re-protection',
                text: 'This will remove the existing protection and create new protection files with the current redundancy level (' + redundancy + '%). Continue?',
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, re-protect',
                cancelButtonText: 'Cancel'
            }, function(confirmed) {
                if (confirmed) {
                    // Step 1: Remove protection
                    P.queueManager.addToQueue(
                        'remove',
                        { path: path, id: id },
                        function(removeResponse) {
                            // Step 2: Add protection after a delay with default redundancy
                            setTimeout(function() {
                                P.queueManager.addToQueue(
                                    'protect',
                                    {
                                        path: path,
                                        redundancy: redundancy
                                    },
                                    function(protectResponse) {
                                        swal({
                                            title: 'Re-protection Started',
                                            text: 'Re-protect task has been added to the queue with the current redundancy level (' + redundancy + '%)',
                                            type: 'info'
                                        });
                                        
                                        // Refresh the list to show updated status
                                        list.refreshProtectedList(true);
                                        P.setLoading(false);
                                    },
                                    function(error) {
                                        P.logger.error('Failed to add protect to queue during re-protection:', { error });
                                        swal('Error', 'Failed to add protection: ' + error, 'error');
                                        P.setLoading(false);
                                    }
                                );
                            }, 2000); // 2 second delay to ensure remove operation has time to process
                        },
                        function(error) {
                            P.logger.error('Failed to add remove to queue during re-protection:', { error });
                            swal('Error', 'Failed to add remove protection to queue: ' + error, 'error');
                            P.setLoading(false);
                        }
                    );
                } else {
                    P.setLoading(false);
                }
            });
        },
        // Show error details popup
        showErrorDetailsPopup: function(operationType, details) {
            
            // Format operation type for display
            let formattedType = '';
            switch(operationType.toLowerCase()) {
                case 'verify':
                    formattedType = 'Verification';
                    break;
                case 'repair':
                    formattedType = 'Repair';
                    break;
                case 'protect':
                    formattedType = 'Protection';
                    break;
                case 'remove':
                    formattedType = 'Removal';
                    break;
                case 'error':
                    formattedType = 'Operation';
                    break;
                case 'missing':
                    formattedType = 'Missing Files';
                    break;
                case 'damaged':
                    formattedType = 'Damaged Files';
                    break;
                case 'repair_failed':
                    formattedType = 'Repair Failed';
                    break;
                case 'metadata_issues':
                    formattedType = 'Metadata Issues';
                    break;
                default:
                    formattedType = operationType.charAt(0).toUpperCase() + operationType.slice(1);
            }
            
            // Extract the most relevant error message from the details
            let errorMessage = 'An error occurred during the operation.';
            
            // Try to extract the most relevant error message from the par2 output
            if (details) {
                // Look for common error patterns in par2 output
                if (details.includes('repair is not possible')) {
                    errorMessage = 'Repair is not possible. Too many files are missing or damaged.';
                } else if (details.includes('No PAR2 recovery data found')) {
                    errorMessage = 'No PAR2 recovery data found for this file or directory.';
                } else if (details.includes('not enough recovery blocks')) {
                    errorMessage = 'Not enough recovery blocks available to repair the damaged files.';
                } else if (details.includes('failed to open')) {
                    // Extract the specific file that failed to open
                    const match = details.match(/failed to open ['"](.*?)['"]/);
                    if (match && match[1]) {
                        errorMessage = `Failed to open file: ${match[1]}`;
                    } else {
                        errorMessage = 'Failed to open one or more files.';
                    }
                } else if (details.includes('Permission denied')) {
                    errorMessage = 'Permission denied. Check file permissions.';
                }
                // Add specific message for metadata issues
                else if (operationType.toLowerCase() === 'metadata_issues') {
                    errorMessage = 'Metadata issues detected. File permissions, ownership, or attributes do not match the protected values.';
                }
            }
            
            // Add CSS for the wide SweetAlert if not already added
            if (!document.getElementById('wide-swal-css')) {
                const style = document.createElement('style');
                style.id = 'wide-swal-css';
                style.innerHTML = `
                    .error-details-container {
                        max-height: 400px;
                        overflow-y: auto;
                        text-align: left;
                        width: 100%;
                    }
                    
                    .error-details-container pre {
                        white-space: pre;
                        background-color: var(--pre-background);
                        color: var(--text);
                        width: 100%;
                        margin: 0;
                        padding: 10px;
                    }
                    
                    .sweet-overlay {
                        z-index: 10000 !important;
                    }
                    .sweet-alert {
                        z-index: 10001 !important;
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Prepare the details HTML
            const detailsHtml = details ? P.escapeHtml(details) : 'No details available';
            
            // Show the error details in a SweetAlert popup with a combined approach
            swal({
                title: `${formattedType} Error`,
                text: errorMessage,
                type: 'error',
                showCancelButton: true,
                confirmButtonText: 'View Details',
                cancelButtonText: 'Close',
                html: true,
                // Ensure proper z-index
                customClass: 'error-popup'
            }, function(isConfirm) {
                if (isConfirm) {
                    // Close current dialog and wait for it to be fully removed
                    swal.close();
                    
                    // Use a more reliable approach with requestAnimationFrame
                    requestAnimationFrame(function() {
                        // Make sure any existing SweetAlert elements are fully removed
                        $('.sweet-overlay, .sweet-alert').remove();
                        
                        // Use requestAnimationFrame again to ensure we're in the next paint cycle
                        requestAnimationFrame(function() {
                        swal({
                            title: `${formattedType} Error Details`, 
                            text: `<div class="error-details-container" style="width:100%;"><pre style="white-space:pre; width:95%;">${detailsHtml}</pre></div>`, 
                            type: 'error', 
                            html: true,
                            customClass: 'wide-swal', 
                            closeOnConfirm: true
                        }, function() {
                            // Force cleanup of any lingering SweetAlert elements
                            setTimeout(function() {
                                $('.sweet-overlay, .sweet-alert').remove();
                            }, 100);
                        });
                        });
                    });
                } else {
                    // Force cleanup when Cancel is clicked
                    setTimeout(function() {
                        $('.sweet-overlay, .sweet-alert').remove();
                    }, 100);
                }
            });
        },
        
        // Setup event listeners
        setupEventListeners: function() {
            // Handle verify button clicks
            $(document).on('click', '.verify-btn', function(e) {
                e.preventDefault();
                const path = $(this).data('path');
                // Get the ID directly from the button's data attribute
                const id = $(this).data('id');
                list.verifySelected(path, true);
            });
            
            // Handle size info icon clicks
            $(document).on('click', '.size-info-icon', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Ensure any existing SweetAlert dialogs are properly closed
                if ($('.sweet-alert').length > 0) {
                    swal.close();
                    // Give a moment for the close animation to complete
                    setTimeout(function() {
                        $('.sweet-overlay, .sweet-alert').remove();
                    }, 100);
                }
                
                // Show size information popup
                requestAnimationFrame(function() {
                    swal({
                        title: 'Size Information',
                        text: 'The Size column shows two values in the format:<br><br>' +
                              '<strong>Protection Files / Protected Data</strong><br><br>' +
                              'The first value shows the size of the PAR2 protection files.<br>' +
                              'The second value shows the size of the actual protected data files.',
                        type: 'info',
                        html: true
                    });
                });
            });
            
            // Handle repair button clicks
            $(document).on('click', '.repair-btn', function(e) {
                e.preventDefault();
                const path = $(this).data('path');
                // Get the ID directly from the button's data attribute
                const id = $(this).data('id');
                
                // Update repairItem to use ID
                P.setLoading(true);
                
                // Prepare parameters
                const params = { path: path };
                if (id) {
                    params.id = id;
                }
                
                // Use queue manager to add repair to queue
                P.queueManager.addToQueue(
                    'repair',
                    params,
                    function(response) {
                        swal({
                            title: 'Repair Started',
                            text: 'Repair task has been added to the queue',
                            type: 'info'
                        });
                        
                        // Refresh the list to show updated status - show loading for manual refresh
                        list.refreshProtectedList(true);
                        P.setLoading(false);
                    },
                    function(error) {
                        P.logger.error('Failed to add repair to queue:', { error });
                        swal('Error', error, 'error');
                        P.setLoading(false);
                    }
                );
            });
            
            // Handle re-protect button clicks
            $(document).on('click', '.reprotect-btn', function(e) {
                e.preventDefault();
                const path = $(this).data('path');
                // Get the ID directly from the button's data attribute
                const id = $(this).data('id');
                
                P.setLoading(true);
                
                // Use default redundancy directly
                const defaultRedundancy = P.settings?.default_redundancy || 10;
                console.log('Using default redundancy:', defaultRedundancy);
                
                // Show confirmation dialog with default redundancy
                swal({
                    title: 'Confirm Re-protection',
                    text: 'This will remove the existing protection and create new protection files with the default redundancy level (' + defaultRedundancy + '%). Continue?',
                    type: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, re-protect',
                    cancelButtonText: 'Cancel'
                }, function(confirmed) {
                    if (confirmed) {
                        // Step 1: Remove protection
                        P.queueManager.addToQueue(
                            'remove',
                            { path: path, id: id },
                            function(removeResponse) {
                                // Step 2: Add protection after a delay with default redundancy
                                setTimeout(function() {
                                    P.queueManager.addToQueue(
                                        'protect',
                                        {
                                            path: path,
                                            redundancy: defaultRedundancy
                                        },
                                        function(protectResponse) {
                                            swal({
                                                title: 'Re-protection Started',
                                                text: 'Re-protect task has been added to the queue with the default redundancy level (' + defaultRedundancy + '%)',
                                                type: 'info'
                                            });
                                            
                                            // Refresh the list to show updated status
                                            list.refreshProtectedList(true);
                                            P.setLoading(false);
                                        },
                                        function(error) {
                                            P.logger.error('Failed to add protect to queue during re-protection:', { error });
                                            swal('Error', 'Failed to add protection: ' + error, 'error');
                                            P.setLoading(false);
                                        }
                                    );
                                }, 2000); // 2 second delay to ensure remove operation has time to process
                            },
                            function(error) {
                                P.logger.error('Failed to add remove to queue during re-protection:', { error });
                                swal('Error', 'Failed to add remove protection to queue: ' + error, 'error');
                                P.setLoading(false);
                            }
                        );
                    } else {
                        P.setLoading(false);
                    }
                });
            });

            // Handle remove button clicks
            $(document).on('click', '.remove-btn', function(e) {
                e.preventDefault();
                const path = $(this).data('path');
                // Get the ID directly from the button's data attribute
                const id = $(this).data('id');
                list.removeItem(path, id);
            });

            // Handle checkbox changes
            $(document).on('change', '.select-item', function() {
                list.updateSelectedButtons();
                // Uncheck "select all" if any checkbox is unchecked
                if (!$(this).prop('checked')) {
                    $('#selectAll').prop('checked', false);
                }
                // Check "select all" if all checkboxes are checked
                else if ($('.select-item:not(:checked)').length === 0) {
                    $('#selectAll').prop('checked', true);
                }
            });
            
            // Handle refresh button clicks
            $(document).on('click', '#refresh-list-btn', function(e) {
                e.preventDefault();
                // Show loading for manual refresh triggered by button click
                list.refreshProtectedList(true);
            });
            
            // Handle error info icon clicks
            $(document).on('click', '.error-info-icon', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Add diagnostic logging for error info icon click
                P.logger.debug("DIAGNOSTIC: Error info icon clicked", {
                    'status': $(this).closest('.status-cell').data('status'),
                    'has_details': $(this).closest('.status-cell').data('details') ? true : false
                });
                
                // Ensure any existing SweetAlert dialogs are properly closed
                if ($('.sweet-alert').length > 0) {
                    P.logger.debug("DIAGNOSTIC: Existing SweetAlert found, closing it first");
                    swal.close();
                    // Give a moment for the close animation to complete
                    setTimeout(function() {
                        $('.sweet-overlay, .sweet-alert').remove();
                    }, 200); // Increased delay to ensure complete removal
                }
                
                const $cell = $(this).closest('.status-cell');
                const status = $cell.data('status');
                const details = $cell.data('details');
                
                if ((status === 'ERROR' || status === 'Error' || status === 'REPAIR_FAILED' || status === 'MISSING' || status === 'DAMAGED' || status === 'METADATA_ISSUES') && details) {
                    // Use setTimeout with a longer delay to ensure DOM is ready
                    setTimeout(function() {
                        // Double requestAnimationFrame for more reliable rendering
                        requestAnimationFrame(function() {
                            requestAnimationFrame(function() {
                                list.showErrorDetailsPopup(status.toLowerCase(), details);
                            });
                        });
                    }, 250); // Added delay before showing the popup
                }
            });
                            
                            // Handle file type info icon clicks
                            $(document).on('click', '.file-type-info', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                const $row = $(this).closest('tr');
                                const path = $row.find('td:nth-child(3)').text();
                                const mode = $row.find('td:nth-child(4)').text().trim();
                                
                                // Add diagnostic logging for info icon click
                                P.logger.debug("DIAGNOSTIC: File type info icon clicked", {
                                    'id': $row.find('td:nth-child(2)').text(),
                                    'path': path,
                                    'mode': mode,
                                    'details': $(this).data('details')
                                });
                                
                                // Ensure any existing SweetAlert dialogs are properly closed
                                if ($('.sweet-alert').length > 0) {
                                    swal.close();
                                    // Give a moment for the close animation to complete
                                    setTimeout(function() {
                                        $('.sweet-overlay, .sweet-alert').remove();
                                    }, 200); // Increased delay to ensure complete removal
                                }
                                
                                
                                // Show loading indicator
                                P.setLoading(true);
                                
                                // If this is Individual Files mode, fetch the actual files
                                if (mode.includes('Individual Files')) {
                                    // Extract file types from the data-details attribute
                                    const fileTypes = $(e.target).data('details').split('<br>')[0].replace('Protected file types: ', '').split(', ');
                                    
                                    // Add diagnostic logging for AJAX request
                                    P.logger.debug("DIAGNOSTIC: Fetching individual files", {
                                        'id': $row.find('td:nth-child(2)').text(),
                                        'path': path,
                                        'mode': mode,
                                        'file_types': fileTypes,
                                        'endpoint': '/plugins/par2protect/api/v1/index.php?endpoint=protection/files'
                                    });
                                    
                                    // Fetch individual files for this directory
                                    $.ajax({
                                        url: '/plugins/par2protect/api/v1/index.php?endpoint=protection/files',
                                        method: 'POST',
                                        data: { 
                                            path: path,
                                            file_types: fileTypes.join(',')
                                        },
                                        dataType: 'json',
                                        success: function(response) {
                                            P.setLoading(false);
                                            
                                            // Add diagnostic logging for AJAX response
                                            P.logger.debug("DIAGNOSTIC: Individual files response", {
                                                'success': response.success,
                                                'data_length': response.data ? response.data.length : 0,
                                                'error': response.error || null
                                            });
                                            
                                            if (response.success && response.data) {
                                                // Check if we have a direct response with files or if we need to extract from the first item
                                                let files = [];
                                                
                                                // Add diagnostic logging for response data structure
                                                P.logger.debug("DIAGNOSTIC: Response data structure", {
                                                    'data_type': typeof response.data,
                                                    'is_array': Array.isArray(response.data),
                                                    'first_item': response.data.length > 0 ? {
                                                        'has_protected_files': response.data[0].protected_files ? true : false,
                                                        'protected_files_length': response.data[0].protected_files ? response.data[0].protected_files.length : 0
                                                    } : null
                                                });
                                                
                                                // Check if the response contains the directory item with protected_files
                                                if (response.data.length === 1 && response.data[0].protected_files) {
                                                    // Use the protected_files array from the directory item
                                                    P.logger.debug("DIAGNOSTIC: Using protected_files from directory item", {
                                                        'protected_files_length': response.data[0].protected_files.length
                                                    });
                                                    
                                                    // Convert the protected_files array to file objects
                                                    files = response.data[0].protected_files.map(filePath => {
                                                        return { path: filePath };
                                                    });
                                                } else {
                                                    // Use the response data directly
                                                    files = response.data;
                                                }
                                                
                                                // Build the file list HTML
                                                let fileListHtml = `<div class="file-list-container">
                                                    <p><strong>Protected file types:</strong> ${fileTypes.join(', ')}</p>
                                                    <p><strong>Protected files (${files.length}):</strong></p>
                                                    <div class="file-list">`;
                                                
                                                if (files.length > 0) {
                                                    fileListHtml += '<ul>';
                                                    files.forEach(file => {
                                                        // Extract just the filename from the path
                                                        const fileName = file.path.split('/').pop();
                                                        fileListHtml += `<li>${fileName}</li>`;
                                                    });
                                                    fileListHtml += '</ul>';
                                                } else {
                                                    fileListHtml += '<p>No files currently protected.</p>';
                                                }
                                                
                                                fileListHtml += '</div></div>';
                                                
                                                // Show the file list in a popup
                                                // Use setTimeout with a longer delay to ensure DOM is ready
                                                setTimeout(function() {
                                                    // Double requestAnimationFrame for more reliable rendering
                                                    requestAnimationFrame(function() {
                                                        requestAnimationFrame(function() {
                                                            swal({
                                                                title: 'Protected Files', 
                                                                text: `<div style="width:100%;">${fileListHtml}</div>`, 
                                                                type: 'info', 
                                                                html: true, 
                                                                customClass: 'wide-swal'
                                                            });
                                                        });
                                                    });
                                                }, 250); // Added delay before showing the popup
                                                
                                            } else {
                                                // Show error
                                                swal({
                                                    title: 'Error',
                                                    text: 'Failed to fetch protected files: ' + (response.error || 'Unknown error'),
                                                    type: 'error'
                                                });
                                            }
                                        },
                                        error: function(xhr, status, error) {
                                            P.setLoading(false);
                                            
                                            // Show error
                                            swal({
                                                title: 'Error',
                                                text: 'Failed to fetch protected files: ' + error,
                                                type: 'error'
                                            });
                                        }
                                    });
                                } else {
                                    // For regular files, just show the details
                                    const details = $(this).data('details');
                                    P.setLoading(false);
                                    
                                    if (details) {
                                        // Use setTimeout with a longer delay to ensure DOM is ready
                                                        setTimeout(function() {
                                                            // Double requestAnimationFrame for more reliable rendering
                                                            requestAnimationFrame(function() {
                                                                requestAnimationFrame(function() {
                                                                    swal({
                                                                        title: 'File Type Information',
                                                                        text: details,
                                                                        type: 'info',
                                                                        html: true
                                            });
                                                                                        });
                                                            });
                                                        }, 250); // Added delay before showing the popup
                                    }
                                }
                            });
        },
        
        // Setup operation completion listeners
        setupOperationListeners: function() {
            // Listen for operation completion events
            P.events.on('operation.completed', function(data) {
                
                // Refresh the list when relevant operations complete
                if (P.protectedListOperations.includes(data.type)) {
                    
                    // Refresh immediately first
                    list.refreshProtectedList(true);
                    
                    // Then add a longer delay to ensure the server has updated its data
                    setTimeout(function() {
                        // Always force a refresh with loading indicator to ensure UI updates
                        console.log('Forcing second list refresh after operation completion');
                        list.refreshProtectedList(true);
                        
                        // Show notification based on operation type and status
                        let message = '';
                        let type = data.status === 'completed' ? 'success' : 'error';
                        let details = data.result ? data.result.details : null;
                        let operationStatus = data.result ? data.result.status : null;
                        
                        switch (data.type) {
                            case 'protect':
                                message = data.status === 'completed'
                                    ? 'Protection operation completed successfully'
                                    : 'Protection operation failed';
                                break;
                            case 'verify':
                                message = data.status === 'completed'
                                    ? 'Verification operation completed successfully'
                                    : 'Verification operation failed';
                                break;
                            case 'repair':
                                message = data.status === 'completed'
                                    ? 'Repair operation completed successfully'
                                    : 'Repair operation failed';
                                break;
                            case 'remove':
                                message = data.status === 'completed'
                                    ? 'Protection removed successfully'
                                    : 'Failed to remove protection';
                                break;
                        }
                        
                        if (message) {
                            addNotice(message, type);
                        }
                        
                        // Show detailed error popup for failed operations
                        if (data.status !== 'completed' &&
                            (operationStatus === 'ERROR' || operationStatus === 'Error' ||
                             operationStatus === 'REPAIR_FAILED' ||
                             operationStatus === 'MISSING' ||
                             operationStatus === 'DAMAGED') &&
                            details) {
                            list.showErrorDetailsPopup(data.type, details);
                        }
                        
                        // Schedule another refresh after a delay to ensure all database changes are reflected
                        setTimeout(function() {
                            list.refreshProtectedList(false);
                        }, 2000);
                    }, 1000); // 1000ms delay to ensure server has updated
                }
            });
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

    // Auto-refresh timer
    let autoRefreshTimer = null;
    
    // Flag to track if operations are in progress
    let operationsInProgress = false;
    
    // Check if there are active operations
    function hasActiveOperations() {
        // Check if queue manager has active operations
        if (P.queueManager && typeof P.queueManager.hasActiveOperations === 'function') {
            return P.queueManager.hasActiveOperations();
        }
        
        // Fallback: check if there are any operations in the queue
        return P.config && P.config.queueStatus && 
               P.config.queueStatus.queue && 
               P.config.queueStatus.queue.length > 0;
    }
    
    // Start auto-refresh
    function startAutoRefresh() {
        // No need to set up a timer for auto-refresh since we're using SSE now
        P.logger.debug('Auto-refresh timer disabled - using SSE for updates instead');
    }
    
    // Add list methods to Par2Protect
    P.list = list;

    // Initialize when document is ready
    $(document).ready(function() {
        if (document.querySelector('.protected-files-list')) {
            // Add additional styles for SweetAlert dialogs
    // Load settings if not already loaded
            if (!P.settings) {
                $.ajax({
                    url: '/plugins/par2protect/api/v1/index.php?endpoint=settings',
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            P.settings = response.data;
                        }
                    },
                    error: function(xhr, status, error) {
                        P.logger.error('Failed to load settings:', { error });
                    },
                    complete: function() {
                        // Setup event listeners and load list regardless of settings load success
                        P.list.setupEventListeners();
                        P.list.setupOperationListeners();
                        
                        // Check if there are any recently completed operations
                        if (P.config && P.config.recentlyCompletedOperations && P.config.recentlyCompletedOperations.length > 0) {
                            // Force a refresh with loading indicator to ensure UI updates
                            P.list.refreshProtectedList(true);
                        } else {
                            // Normal refresh
                            P.list.refreshProtectedList();
                        }
                        
                        // Start auto-refresh timer
                        startAutoRefresh();
                    }
                });
            } else {
                P.list.setupEventListeners();
                P.list.setupOperationListeners();
                
                // Check if there are any recently completed operations
                if (P.config && P.config.recentlyCompletedOperations && P.config.recentlyCompletedOperations.length > 0) {
                    // Force a refresh with loading indicator to ensure UI updates
                    P.list.refreshProtectedList(true);
                } else {
                    // Normal refresh
                    P.list.refreshProtectedList();
                }
                
                // Start auto-refresh timer
                startAutoRefresh();
            }
        }
    });

})(window.Par2Protect);