// Simplified version of re-protect fix script using the API endpoint
(function() {
    // Make sure Par2Protect namespace exists
    window.Par2Protect = window.Par2Protect || {};
    
    // Get a reference to the Par2Protect namespace
    const P = window.Par2Protect;
    
    // Function to apply the patch
    function applyPatch() {
        // Check if Par2Protect and list are available
        if (typeof window.Par2Protect === 'undefined' || typeof window.Par2Protect.list === 'undefined') {
            // Wait silently for Par2Protect.list to be available
            setTimeout(applyPatch, 1000);
            return;
        }
            
        // P.logger.info('Re-protect fix: Applying patch to reprotectSelected function');
        
        // Create a new function that will replace the original
        function patchedReprotectSelected() {
            // P.logger.info('Re-protect fix: Patched reprotectSelected called');
            // Store both paths and IDs
                let selectedItems = [];
            
            $('.select-item:checked').each(function() {
                const $row = $(this).closest('tr');
                    const id = $row.find('td:nth-child(2)').text();
                    const path = $row.find('td:nth-child(3)').text();
                    selectedItems.push({ id: id, path: path });
            });

            // Only log paths if debug logging is enabled (handled by logger)

            if (selectedItems.length === 0) {
                swal('Error', 'Please select items to re-protect', 'error');
                return;
            }

            // Show confirmation dialog
            swal({
                title: 'Confirm Re-protection',
                text: 'This will remove the existing protection and create new protection files for ' + selectedItems.length + ' item(s). Continue?',
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, re-protect',
                cancelButtonText: 'Cancel',
                closeOnConfirm: false // Don't close automatically
            }, function(confirmed) {
                
                if (confirmed) {
                    // User confirmed, show redundancy options
                    // P.logger.info('Re-protect fix: User confirmed, showing redundancy options');
                    
                    // Get the default redundancy from settings
                    const defaultRedundancy = P.settings?.default_redundancy || 10;
                    
                    // Show redundancy options dialog
                    swal({
                        title: 'Re-protection Options',
                        text: 'Choose redundancy level for re-protecting ' + selectedItems.length + ' item(s):',
                        type: 'info',
                        showCancelButton: true,
                        confirmButtonText: 'Continue',
                        cancelButtonText: 'Cancel',
                        html: true,
                        closeOnConfirm: false
                    });
                    
                    // Show loading indicator in the dialog
                    $('.sweet-alert p').after('<div id="redundancy-loading">Loading previous redundancy levels...</div>');
                    
                    // Get redundancy levels from API
                    $.ajax({
                        url: '/plugins/par2protect/api/v1/index.php?endpoint=protection/redundancy',
                        type: 'POST',
                        data: { 
                                paths: JSON.stringify(selectedItems.map(item => item.path)),
                                ids: JSON.stringify(selectedItems.map(item => item.id))
                            },
                        dataType: 'json'
                    })
                    .done(function(response) {
                        // Remove loading indicator
                        $('#redundancy-loading').remove();
                        
                        // Success handler will be called here
                        
                        if (response.success) {
                            const redundancyLevels = response.data;
                            P.logger.debug('Re-protect fix: Retrieved redundancy levels:', { redundancyLevels });
                            
                            // Check if we have any previous redundancy levels
                            const hasPreviousLevels = Object.values(redundancyLevels).some(level => level !== null);
                            
                            // Function to format the previous redundancy display text
                            function formatPreviousRedundancy(redundancyLevels, paths) {
                                // Filter out null values and get unique redundancy values
                                const uniqueValues = [...new Set(
                                    paths.map(item => redundancyLevels[item.path])
                                        .filter(value => value !== null && value !== undefined)
                                )];
                                
                                if (uniqueValues.length === 0) {
                                    return "from database";
                                } else if (uniqueValues.length === 1) {
                                    // All paths have the same redundancy value
                                    return uniqueValues[0] + "%";
                                } else if (uniqueValues.length <= 3) {
                                    // Show up to 3 different values
                                    return uniqueValues.join("%, ") + "%";
                                } else {
                                    // Too many different values, show range
                                    const min = Math.min(...uniqueValues);
                                    const max = Math.max(...uniqueValues);
                                    return `${min}% - ${max}%`;
                                }
                            }
                            
                            // Create the options HTML with all three options
                            const optionsHtml = `
                                <div style="text-align: left; margin-top: 15px;">
                                    <form id="redundancy-options-form">
                                        <div style="margin-bottom: 10px;">
                                            <label>
                                                <input type="radio" name="redundancy-option" value="default" checked>
                                                Use default redundancy (${defaultRedundancy}%)
                                            </label>
                                        </div>
                                        <div style="margin-bottom: 10px;">
                                            <label>
                                                <input type="radio" name="redundancy-option" value="custom">
                                                Use custom redundancy
                                            </label>
                                        </div>
                                        ${hasPreviousLevels ? `
                                        <div style="margin-bottom: 10px;">
                                            <label>
                                                <input type="radio" name="redundancy-option" value="previous">
                                                Use previous redundancy (${formatPreviousRedundancy(redundancyLevels, selectedItems)})
                                            </label>
                                        </div>
                                        ` : ''}
                                        <div id="custom-redundancy-container" style="display: none; margin-top: 10px; padding-left: 20px;">
                                            <div style="display: flex; align-items: center;">
                                                <input type="range" id="custom-redundancy-slider" min="1" max="100" value="${defaultRedundancy}" style="flex-grow: 1; margin-right: 10px;">
                                                <span id="custom-redundancy-value">${defaultRedundancy}%</span>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            `;
                            
                            // Add the options to the dialog
                            $('.sweet-alert p').after(optionsHtml);
                            
                            // Show/hide custom redundancy slider when the option is selected
                            $('input[name="redundancy-option"]').on('change', function() {
                                if ($(this).val() === 'custom') {
                                    $('#custom-redundancy-container').show();
                                } else {
                                    $('#custom-redundancy-container').hide();
                                }
                            });
                            
                            // Update the displayed value when the slider is moved
                            $('#custom-redundancy-slider').on('input', function() {
                                $('#custom-redundancy-value').text($(this).val() + '%');
                            });
                            
                            // Replace the SweetAlert dialog with our own custom handling
                            $('.sweet-alert .confirm').off('click').on('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                
                                // Get the selected redundancy option
                                const selectedOption = $('input[name="redundancy-option"]:checked').val();
                                
                                if (!selectedOption) {
                                    alert('Please select a redundancy option');
                                    return;
                                }
                                
                                // Read the custom redundancy value before removing the SweetAlert elements
                                let customRedundancy;
                                if (selectedOption === 'custom') {
                                    customRedundancy = parseInt($('#custom-redundancy-slider').val(), 10);
                                }
                                
                                // Remove all SweetAlert elements from the DOM
                                $('.sweet-overlay, .sweet-alert').remove();
                                
                                // Show loading indicator
                                P.setLoading(true);
                                
                                // Add diagnostic logging for re-protect operation
                                P.logger.debug("DIAGNOSTIC: Re-protect operation starting", {
                                    'selected_items': selectedItems,
                                    'redundancy_option': selectedOption,
                                    'custom_redundancy': customRedundancy
                                });
                                
                                // Call the reprotect API endpoint
                                $.ajax({
                                    url: '/plugins/par2protect/api/v1/index.php?endpoint=protection/reprotect',
                                    type: 'POST',
                                    data: {
                                        paths: JSON.stringify(selectedItems.map(item => item.path)),
                                        ids: JSON.stringify(selectedItems.map(item => item.id)),
                                        redundancy_option: selectedOption,
                                        custom_redundancy: customRedundancy
                                    },
                                    dataType: 'json'
                                })
                                .done(function(response) {
                                    P.setLoading(false);
                                    
                                    if (response.success) {
                                        // P.logger.info('Re-protect fix: Re-protection operations added to queue:', { 
                                        //     paths_count: response.data.paths_count,
                                        //     operations_count: response.data.operations_count
                                        // });
                                        
                                        // Manually trigger a dashboard refresh
                                        if (P.dashboard && typeof P.dashboard.updateStatus === 'function') {
                                            P.logger.debug('Re-protect fix: Manually triggering dashboard update');
                                        } else {
                                            P.logger.error('Re-protect fix: Cannot trigger dashboard update - dashboard object not available');
                                        }
                                        
                                        // Show success message
                                        swal({
                                            title: 'Re-protection Started',
                                            text: response.message,
                                            type: 'info'
                                        });
                                        
                                        // Uncheck all checkboxes
                                        $('.select-item').prop('checked', false);
                                        $('#selectAll').prop('checked', false);
                                        P.list.updateSelectedButtons();
                                        
                                        // Refresh the list to show updated status
                                        P.list.refreshProtectedList(true);
                                    } else {
                                        P.logger.error('Re-protect fix: Failed to add re-protection operations to queue:', { 
                                            error: response.error
                                        });
                                        
                                        // Show error message
                                        swal('Error', response.error, 'error');
                                    }
                                })
                                .fail(function(xhr, status, error) {
                                    P.setLoading(false);
                                    
                                    P.logger.error('Re-protect fix: AJAX error:', {
                                        status: status,
                                        statusText: xhr.statusText,
                                        responseText: xhr.responseText,
                                        readyState: xhr.readyState
                                    });
                                    
                                    // Show error message
                                    swal('Error', 'Failed to add re-protection operations to queue: ' + error, 'error');
                                });
                            });
                            
                            // Add custom handler for the cancel button as well
                            $('.sweet-alert .cancel').off('click').on('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                // P.logger.info('Re-protect fix: User cancelled redundancy options dialog');
                                
                                // Remove all SweetAlert elements from the DOM
                                $('.sweet-overlay, .sweet-alert').remove();
                                
                                // Uncheck all checkboxes to reset the selection state
                                $('.select-item').prop('checked', false);
                                $('#selectAll').prop('checked', false);
                                P.list.updateSelectedButtons();
                                
                                return false;
                            });
                        } else {
                            P.logger.error('Re-protect fix: Failed to get redundancy levels:', { error: response.error });
                            
                            // Show error message
                            swal('Error', 'Failed to get redundancy levels: ' + response.error, 'error');
                        }
                    })
                    .fail(function(xhr, status, error) {
                        // Remove loading indicator
                        $('#redundancy-loading').remove();
                        
                        P.logger.error('Re-protect fix: AJAX error:', {
                            status: status,
                            statusText: xhr.statusText,
                            responseText: xhr.responseText,
                            readyState: xhr.readyState
                        });
                        
                        // Show error message
                        swal('Error', 'Failed to get redundancy levels: ' + error, 'error');
                    });
                } else {
                    // User cancelled, uncheck all checkboxes
                    // P.logger.info('Re-protect fix: User cancelled, unchecking all checkboxes');
                    $('.select-item').prop('checked', false);
                    $('#selectAll').prop('checked', false);
                    P.list.updateSelectedButtons();
                }
            });
        }
        
        try {
            // Check if the reprotectSelected function exists
            if (typeof window.Par2Protect.list.reprotectSelected !== 'function') {
                // Wait silently for reprotectSelected function to be available
                setTimeout(applyPatch, 1000);
                return;
            }
            
            // Replace the original function with our patched version
            window.Par2Protect.list.reprotectSelected = patchedReprotectSelected;
            // P.logger.info('Re-protect fix: Successfully patched reprotectSelected function');
        } catch (e) {
            P.logger.error('Re-protect fix: Error while patching:', { error: e });
            setTimeout(applyPatch, 1000);
        }
    }
    
    // Start applying the patch
    setTimeout(applyPatch, 1000);
})();