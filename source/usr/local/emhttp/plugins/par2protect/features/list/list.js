// Protected Files List Functionality

(function(P) {
    // Ensure P.config exists and initialize necessary properties
    P.config = P.config || {};
    P.config.lastOperationStatus = P.config.lastOperationStatus || {};
    P.config.recentlyCompletedOperations = P.config.recentlyCompletedOperations || [];
    P.config.hasActiveOperations = P.config.hasActiveOperations || false;
    P.protectedListOperations = P.protectedListOperations || ['protect', 'verify', 'repair', 'remove'];

    // List methods
    const list = {
        isRefreshing: false,
        pendingRefresh: false,
        lastRefreshTime: null,
        lastCallSource: null,
        debounceTimeout: null,
        refreshDebounce: null,

        // Helper function to find a button by ID and path
        findButtonByIdAndPath: function(buttonClass, id, path) {
            const uniqueId = id + '-' + encodeURIComponent(path);
            let $button = $('.' + buttonClass + '[data-unique="' + uniqueId + '"]');
            if ($button.length === 0) {
                const $buttons = $('.' + buttonClass + '[data-path="' + path + '"]');
                if ($buttons.length > 1) {
                    $buttons.each(function() { if ($(this).data('id') == id) { $button = $(this); return false; } });
                } else if ($buttons.length === 1) { $button = $buttons; }
            }
            return $button;
        },

        // Update protected files list with provided data
        updateProtectedList: function(items) {
            const checkedPaths = [];
            $('.select-item:checked').each(function() {
                const path = $(this).closest('tr').find('td:nth-child(3)').text();
                checkedPaths.push(path);
            });
            const $list = $('#protected-files-list');
            $list.empty();
            if (items.length === 0) {
                $list.append('<tr><td colspan="10" class="notice">No protected files found</td></tr>');
            } else {
                items.forEach(item => { const row = this.createItemRow(item, checkedPaths); $list.append(row); });
                this.updateSelectAllState();
            }
            $('#item-count').text(items.length);
            this.updateSelectedButtons();
        },

        // Refresh protected files list from API
        refreshProtectedList: function(showLoading = true) {
            const stackTrace = new Error().stack;
            const callSource = this.identifyCallSource(stackTrace);
            const now = Date.now();
            if (this.isRefreshing || (this.lastCallSource === callSource && this.lastRefreshTime && (now - this.lastRefreshTime < 200) && !showLoading)) { return $.Deferred().resolve(); }
            if (this.debounceTimeout) { clearTimeout(this.debounceTimeout); this.debounceTimeout = null; }
            this.isRefreshing = true; this.pendingRefresh = true; this.lastRefreshTime = now; this.lastCallSource = callSource;
            if (showLoading) { P.setLoading(true); }
            const requestId = 'req_' + Math.random().toString(36).substring(2, 15);
            const timestamp = new Date().getTime();
            const cacheBuster = Math.random().toString(36).substring(2, 15);
            const url = `/plugins/par2protect/api/v1/index.php?endpoint=protection&_ts=${timestamp}&_cb=${cacheBuster}`;
            $.ajax({
                url: url, method: 'GET', dataType: 'json', cache: false,
                headers: { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache', 'Expires': '0', 'X-Requested-With': 'XMLHttpRequest' },
                data: { _nocache: new Date().getTime(), _component: 'list', _caller: 'refreshProtectedList', _manual: showLoading ? 'true' : 'false', _call_source: callSource, _random: Math.random().toString(36).substring(2, 15), _request_id: requestId, _force_refresh: 'true' },
                success: function(response) {
                    if (response.success && P.handleDirectOperationResponse) { P.handleDirectOperationResponse(response); }
                    window.requestAnimationFrame(function() {
                        if (response.success) { list.updateProtectedList(response.data || []); }
                        else { P.logger.error('Error in refresh response:', { error: response.error }); $('#error-display').text('Error: ' + response.error).show(); }
                    });
                },
                error: function(xhr, status, error) { P.logger.error('Failed to refresh protected files list:', { status: status, error: error, response: xhr.responseText }); $('#error-display').text('Failed to refresh protected files list: ' + error).show(); },
                complete: function() { if (showLoading) { P.setLoading(false); } setTimeout(() => { $('.loading-overlay').remove(); }, 500); list.isRefreshing = false; list.pendingRefresh = false; list.lastCallSource = null; }
            });
        },

        // Filter list by status
        filterByStatus: function(status) {
            const rows = $('#protected-files-list tr'); P.setLoading(true);
            if (status === 'all') { rows.show(); }
            else { rows.each(function() { const s = $(this).find('td.status-cell').attr('data-status').toLowerCase(); $(this).toggle(status === 'protected' ? (s === 'protected' || s === 'verified' || s === 'repaired') : s === status); }); }
            P.setLoading(false);
        },

        // Filter list by mode
        filterByMode: function(mode) {
            const rows = $('#protected-files-list tr');
            if (mode === 'all') { rows.show(); }
            else { rows.each(function() { $(this).toggle($(this).find('td:nth-child(4)').text().toLowerCase() === mode.toLowerCase()); }); }
        },

        // Toggle all checkboxes
        toggleSelectAll: function(checkbox) { $('.select-item').prop('checked', checkbox.checked); list.updateSelectedButtons(); },

        // Update selected buttons state
        updateSelectedButtons: function() {
            const hasSelected = $('.select-item:checked').length > 0;
            try { $('#removeSelectedBtn, #verifySelectedBtn, #reprotectSelectedBtn').prop('disabled', !hasSelected); }
            catch (e) { console.error("Error setting button disabled property:", e); }
        },

        // Re-protect selected items (Refactored - Close Then Open strategy)
        reprotectSelected: function() {
            let selectedItems = [];
            $('.select-item:checked').each(function() {
                const $row = $(this).closest('tr');
                selectedItems.push({ id: $row.find('td:nth-child(2)').text(), path: $row.find('td:nth-child(3)').text() });
            });
            if (selectedItems.length === 0) { swal('Error', 'Please select items to re-protect', 'error'); return; }

            // --- Step 1: Initial Confirmation ---
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
                            const redundancyLevels = response.data; // This is keyed by ID
                            P.logger.debug('Re-protect fix: Retrieved redundancy levels:', { redundancyLevels });

                            // Check if we have any previous redundancy levels
                            // Note: API returns object keyed by ID, so check values directly
                            const hasPreviousLevels = Object.values(redundancyLevels).some(level => level !== null);

                            // Function to format the previous redundancy display text
                            function formatPreviousRedundancy(redundancyLevels, selectedItems) {
                                // Get all values for selected items using ID, filtering only undefined
                                const allValues = selectedItems.map(item => redundancyLevels[item.id]) // Use item.id for lookup
                                                               .filter(value => value !== undefined);

                                // Get unique values (including null)
                                const allUniqueValuesIncludingNull = [...new Set(allValues)];

                                // Get unique non-null values
                                const nonNullUniqueValues = allUniqueValuesIncludingNull.filter(v => v !== null);

                                if (nonNullUniqueValues.length === 0) {
                                    // Only nulls or empty list found
                                    if (allUniqueValuesIncludingNull.includes(null)) {
                                         return "(None)"; // Indicate that previous value was explicitly null/not set
                                    } else {
                                         // No values found for the selected items
                                         return "(N/A)";
                                    }
                                } else if (nonNullUniqueValues.length === 1) {
                                    // Single non-null value
                                    return nonNullUniqueValues[0] + "%";
                                } else if (nonNullUniqueValues.length <= 3) {
                                    // List of non-null values
                                    return nonNullUniqueValues.join("%, ") + "%";
                                } else {
                                    // Range of non-null values
                                    const min = Math.min(...nonNullUniqueValues);
                                    const max = Math.max(...nonNullUniqueValues);
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
                                                Use previous redundancy ${formatPreviousRedundancy(redundancyLevels, selectedItems)}
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

                                // Determine the redundancy value to send based on the selected option
                                let redundancyToSend = null;
                                if (selectedOption === 'default') {
                                    redundancyToSend = defaultRedundancy; // Use the default value fetched earlier
                                } else if (selectedOption === 'custom') {
                                    redundancyToSend = customRedundancy; // Use the custom value read earlier
                                } else if (selectedOption === 'previous') {
                                    redundancyToSend = null; // Send null for 'previous', backend should handle it
                                }

                                P.logger.debug("DIAGNOSTIC: Re-protect operation starting", {
                                    'selected_items_ids': selectedItems.map(item => item.id),
                                    'selected_option': selectedOption,
                                    'redundancy_to_send': redundancyToSend
                                });

                                // Call the reprotect API endpoint with the expected data format
                                $.ajax({
                                    url: '/plugins/par2protect/api/v1/index.php?endpoint=protection/reprotect',
                                    type: 'POST',
                                    data: {
                                        // paths: JSON.stringify(selectedItems.map(item => item.path)), // Remove paths
                                        ids: JSON.stringify(selectedItems.map(item => item.id)), // Keep ids
                                        // redundancy_option: selectedOption, // Remove redundancy_option
                                        // custom_redundancy: customRedundancy // Remove custom_redundancy
                                        redundancy: redundancyToSend // Send the single calculated value (or null)
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
        }, // End of reprotectSelected

        // Remove selected protections
        removeSelectedProtections: function(singlePath = null) {
            let itemsToRemove = [];
            if (singlePath) {
                let foundId = null;
                $('#protected-files-list tr').each(function() { if ($(this).find('td:nth-child(3)').text() === singlePath) { foundId = $(this).find('td:nth-child(2)').text(); return false; } });
                if (foundId) { itemsToRemove.push({ id: foundId, path: singlePath }); }
                else { P.logger.error("Could not find ID for path to remove", { path: singlePath }); swal('Error', 'Could not find item to remove.', 'error'); return; }
            } else {
                $('.select-item:checked').each(function() { const $row = $(this).closest('tr'); itemsToRemove.push({ id: $row.find('td:nth-child(2)').text(), path: $row.find('td:nth-child(3)').text() }); });
            }
            if (itemsToRemove.length === 0) { swal('Error', 'Please select items to remove.', 'error'); return; }

            swal({
                title: 'Confirm Removal', text: `Are you sure you want to remove protection for ${itemsToRemove.length} item(s)? This will delete associated PAR2 files and database entries.`,
                type: 'warning', showCancelButton: true, confirmButtonText: 'Yes, remove', cancelButtonText: 'Cancel', closeOnConfirm: false
            }, function(confirmed) {
                if (confirmed) {
                    P.setLoading(true);
                    P.logger.debug("Sending remove request", { count: itemsToRemove.length, isSingle: !!singlePath });

                    // Determine parameters based on single vs bulk
                    const parametersToSend = singlePath ? itemsToRemove[0] : { items: itemsToRemove };
                    const successMessage = singlePath ? 'Item queued for removal.' : `${itemsToRemove.length} item(s) added to queue for removal.`;

                    P.queueManager.addToQueue('remove', parametersToSend,
                        function(response) { // Success callback
                            P.logger.debug('Removal added to queue successfully', { response, paramsSent: parametersToSend });
                            swal({ title: 'Removal Queued', text: successMessage, type: 'success' });
                            setTimeout(() => list.refreshProtectedList(false), 1000);
                            P.setLoading(false);
                        },
                        function(error) { // Error callback
                            P.logger.warning('Queue add reported error for removal', { error, paramsSent: parametersToSend });
                            // Handle specific errors like "already in queue" if the backend supports it for single items
                            if (typeof error === 'string' && error.includes("already in queue")) {
                                swal({ title: 'Already Queued', text: error, type: 'info' });
                            } else {
                                swal({ title: 'Error', text: P.escapeHtml(error), type: 'error' });
                            }
                            P.setLoading(false);
                        }
                    );
                }
            });
        },

        // Verify selected items
        verifySelected: function(target = null, force = false, id = null) {
            let itemsToVerify = [];
            if (id && target) { itemsToVerify.push({ id: id, path: target }); }
            else { $('.select-item:checked').each(function() { const $row = $(this).closest('tr'); itemsToVerify.push({ id: $row.find('td:nth-child(2)').text(), path: $row.find('td:nth-child(3)').text() }); }); }
            if (itemsToVerify.length === 0) { swal('Error', 'Please select items to verify.', 'error'); return; }
            list.showVerificationOptionsDialog(itemsToVerify, force);
        },

        // Show verification options dialog
        showVerificationOptionsDialog: function(items, force) {
            $.get('/plugins/par2protect/features/list/verification-options-dialog.php', function(dialogHtml) {
                swal({
                    title: 'Verification Options', text: `Select options for verifying ${items.length} item(s):`, html: dialogHtml,
                    type: 'info', showCancelButton: true, confirmButtonText: 'Start Verification', cancelButtonText: 'Cancel', closeOnConfirm: false
                }, function(isConfirm) {
                    if (isConfirm) {
                        const verifyMetadata = $('#verify-metadata-checkbox').is(':checked');
                        const autoRestoreMetadata = $('#auto-restore-metadata-checkbox').is(':checked');
                        P.setLoading(true);
                        P.logger.debug("Starting verification for selected items", { count: items.length, verifyMetadata, autoRestoreMetadata, force });
                        let successCount = 0; let failCount = 0; let promises = [];
                        items.forEach(item => {
                            const params = { id: item.id, path: item.path, verify_metadata: verifyMetadata, auto_restore_metadata: autoRestoreMetadata, force: force };
                            promises.push(new Promise((resolve, reject) => {
                                P.queueManager.addToQueue('verify', params,
                                    (response) => { successCount++; resolve(response); },
                                    (error) => { failCount++; P.logger.error('Failed to queue verification', { item, error }); reject(error); }
                                );
                            }));
                        });
                        Promise.allSettled(promises).then(() => {
                            P.setLoading(false);
                            let message = '';
                            if (successCount > 0) message += `${successCount} verification task(s) added to the queue. `;
                            if (failCount > 0) message += `${failCount} task(s) failed to queue.`;
                            swal({ title: failCount > 0 ? 'Verification Queued (with errors)' : 'Verification Queued', text: message, type: failCount > 0 ? 'warning' : 'success' });
                            list.refreshProtectedList(false);
                        });
                    }
                });
            }).fail(function() { swal('Error', 'Could not load verification options dialog.', 'error'); });
        },

        // Show error details popup
        showErrorDetailsPopup: function(status, details) {
            details = String(details);
            const formattedDetails = P.escapeHtml(details).replace(/\n/g, '<br>');
            swal({ title: `Details (${status.toUpperCase()})`, text: `<div style="max-height: 300px; overflow-y: auto; text-align: left;">${formattedDetails}</div>`, html: true, type: 'error' });
        },

        // Setup event listeners for the list page
        setupEventListeners: function() {
            // Filter listeners
            $('#statusFilter').on('change', function() { list.filterByStatus(this.value); });
            $('#modeFilter').on('change', function() { list.filterByMode(this.value); });

            // Select all checkbox
            $('#selectAll').on('change', function() { list.toggleSelectAll(this); });

            // Individual item checkbox changes (delegated to table body)
            $('#protected-files-list').on('change', '.select-item', function() {
                list.updateSelectedButtons(); // Call the button update function
                // Wrap "Select All" logic in try...catch
                try {
                    if (!$(this).prop('checked')) {
                        $('#selectAll').prop('checked', false);
                    } else if ($('.select-item:not(:checked)').length === 0) {
                        $('#selectAll').prop('checked', true);
                    }
                } catch (e) {
                    console.error("Error updating selectAll checkbox state:", e); // Log any error
                }
            });

            // Action buttons for selected items
            $('#removeSelectedBtn').on('click', function() { list.removeSelectedProtections(); });
            $('#verifySelectedBtn').on('click', function() { list.verifySelected(null, true); }); // Force verify for selected
            $('#reprotectSelectedBtn').on('click', function() { list.reprotectSelected(); });

            // Delegated listeners for action buttons within rows
            $('#protected-files-list').on('click', '.verify-btn', function() { list.verifySelected($(this).data('path'), true, $(this).data('id')); });
            $('#protected-files-list').on('click', '.repair-btn', function() {
                const id = $(this).data('id'); const path = $(this).data('path');
                P.logger.debug('Found repair button for path', { path: path, id: id });
                swal({ title: 'Confirm Repair', text: `Attempt to repair ${path}?`, type: 'warning', showCancelButton: true, confirmButtonText: 'Yes, repair', closeOnConfirm: true },
                    function(confirmed) { if (confirmed) { P.setLoading(true); P.queueManager.addToQueue('repair', { id: id, path: path }, (r) => { swal('Repair Queued', 'Repair task added.', 'success'); list.refreshProtectedList(false); P.setLoading(false); }, (e) => { P.logger.error('Failed to add repair', {e}); swal('Error', P.escapeHtml(e), 'error'); P.setLoading(false); }); } } // Escape error
                );
            });
            $('#protected-files-list').on('click', '.reprotect-btn', function() {
                const $row = $(this).closest('tr'); $('.select-item').prop('checked', false); $row.find('.select-item').prop('checked', true); list.updateSelectedButtons(); list.reprotectSelected();
            });
            $('#protected-files-list').on('click', '.remove-btn', function() { list.removeSelectedProtections($(this).data('path')); });

            // Delegated listener for error info icons
            $('#protected-files-list').on('click', '.error-info-icon', function(e) {
                e.stopPropagation(); const $cell = $(this).closest('.status-cell'); const status = $cell.data('status'); const details = $cell.data('details');
                if (details) { list.showErrorDetailsPopup(status, details); }
            });

             // Delegated listener for file type info icons
             $('#protected-files-list').on('click', '.file-type-info', function(e) {
                 e.stopPropagation(); const details = $(this).data('details');
                 if (details) { swal({ title: 'File Type Info', text: `<div style="text-align: left;">${details}</div>`, html: true, type: 'info' }); }
             });

             // Delegated listener for size info icon
             $('.protected-files-list').on('click', '.size-info-icon', function(e) { // Attach to parent container
                 e.stopPropagation(); const title = $(this).attr('title');
                 if (title) { swal({ title: 'Size Information', text: title, type: 'info' }); }
             });

            // Refresh button
            $('#refresh-list-btn').on('click', function(e) {
                e.preventDefault(); e.stopImmediatePropagation(); P.logger.debug('Refresh button clicked');
                if (list.refreshDebounce) { clearTimeout(list.refreshDebounce); }
                list.refreshDebounce = setTimeout(function() { list.refreshProtectedList(true); }, 500);
            });
        },

        // Setup listeners for operation events from queue manager
        setupOperationListeners: function() {
            P.events.on('operation.completed', function(eventData) {
                P.logger.debug('List.js received operation.completed event', { eventData });
                if (P.protectedListOperations.includes(eventData.type)) {
                    setTimeout(() => { P.logger.debug('Refreshing list due to operation completion', { type: eventData.type, id: eventData.id }); list.refreshProtectedList(false); }, 500);
                }
            });
            P.events.on('queue.cleared', function() { P.logger.debug('List.js received queue.cleared event, refreshing list.'); list.refreshProtectedList(false); });
        },

        // Helper to identify call source from stack trace
        identifyCallSource: function(stackTrace) {
            if (!stackTrace) return 'unknown';
            if (stackTrace.includes('handleOperationCompleted')) return 'sse_op_completed';
            if (stackTrace.includes('handleQueueUpdate')) return 'sse_queue_update';
            if (stackTrace.includes('refreshProtectedList')) return 'manual_refresh';
            return 'other';
        },

        // Create HTML row for a protected item
        createItemRow: function(item, checkedPaths = []) {
            const isChecked = checkedPaths.includes(item.path) ? 'checked' : '';
            let fileTypeInfo = ''; let fileTypeDetails = '';
            const isIndividual = item.mode && item.mode.includes('Individual Files');

            if ((item.mode === 'file' && item.parent_dir) || isIndividual || (item.mode === 'directory' && item.file_types)) {
                if (item.mode === 'file' && item.parent_dir) { fileTypeDetails = `File type: ${item.path.split('.').pop().toLowerCase()}`; }
                else if ((isIndividual || item.mode === 'directory') && item.file_types) {
                    fileTypeDetails = `Protected file types: ${Array.isArray(item.file_types) ? item.file_types.join(', ') : item.file_types}`;
                    if (isIndividual) { fileTypeDetails += '<br>Protecting individual files matching these types'; }
                }
                if (fileTypeDetails) { fileTypeInfo = `<i class="fa fa-info-circle file-type-info" data-details="${P.escapeHtml(fileTypeDetails)}"></i>`; }
            }

            const status = item.last_status || 'Unknown';
            const details = item.last_details ? P.escapeHtml(item.last_details) : '';
            const errorIcon = ['ERROR', 'REPAIR_FAILED', 'MISSING', 'DAMAGED', 'METADATA_ISSUES'].includes(status.toUpperCase()) ? '<i class="fa fa-info-circle error-info-icon"></i>' : '';
            // Use P.formatBytes (corrected name)
            const sizeDisplay = item.par2_size !== null && item.data_size !== null ? `${P.formatBytes(item.par2_size)} / ${P.formatBytes(item.data_size)}` : (item.size_formatted || P.formatBytes(item.size) || 'N/A');
            const repairButton = ['MISSING', 'DAMAGED', 'ERROR', 'REPAIR_FAILED'].includes(status.toUpperCase()) ? `<button class="repair-btn" data-path="${item.path}" data-id="${item.id}" data-unique="${item.id}-${encodeURIComponent(item.path)}">Repair</button>` : '';
            // Only show reprotect button if status is not PROTECTED or VERIFIED
            const showReprotect = !['PROTECTED', 'VERIFIED'].includes(status.toUpperCase());
            const reprotectButton = showReprotect ? `<button class="reprotect-btn" data-path="${item.path}" data-id="${item.id}" data-unique="${item.id}-${encodeURIComponent(item.path)}">Re-protect</button>` : '';

            return `
                <tr>
                    <td><input type="checkbox" class="select-item" ${isChecked}></td>
                    <td style="display:none;">${item.id}</td>
                    <td>${P.escapeHtml(item.path)}</td>
                    <td>${item.mode}${fileTypeInfo}</td>
                    <td>${item.redundancy}%</td>
                    <td>${sizeDisplay}</td>
                    <td class="status-cell" data-status="${status}" data-details="${details}">
                        <span>${status}</span> ${errorIcon}
                    </td>
                    <td>${item.protected_date || 'Unknown'}</td>
                    <td>${item.last_verified || 'Never'}</td>
                    <td>
                        <button class="verify-btn" data-path="${item.path}" data-id="${item.id}" data-unique="${item.id}-${encodeURIComponent(item.path)}">Verify</button>
                        ${repairButton}
                        ${reprotectButton}
                        <button class="remove-btn" data-path="${item.path}" data-id="${item.id}" data-unique="${item.id}-${encodeURIComponent(item.path)}">Remove</button>
                    </td>
                </tr>`;
        },

        // Update select all checkbox state
        updateSelectAllState: function() {
            const allChecked = $('.select-item').length > 0 && $('.select-item:not(:checked)').length === 0;
            $('#selectAll').prop('checked', allChecked);
        }
    };

    // Expose list object
    P.list = list;

    // Initialize when document is ready
    $(document).ready(function() {
        if (document.querySelector('.protected-files-list')) {
            // Load settings first if needed
            if (!P.settings) {
                $.ajax({
                    url: '/plugins/par2protect/api/v1/index.php?endpoint=settings', method: 'GET', dataType: 'json',
                    success: function(response) { if (response.success) { P.settings = response.data; } },
                    error: function(xhr, status, error) { P.logger.error('Failed to load settings:', { error }); },
                    complete: function() {
                        P.list.setupEventListeners();
                        P.list.setupOperationListeners();
                        P.list.refreshProtectedList(true); // Initial load with spinner
                    }
                });
            } else {
                P.list.setupEventListeners();
                P.list.setupOperationListeners();
                P.list.refreshProtectedList(true); // Initial load with spinner
            }
        }
    });

})(window.Par2Protect = window.Par2Protect || {}); // Use existing P object or create new