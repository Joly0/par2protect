// Protected Files List Functionality

(function(P) {
    // List methods
    const list = {
        // Refresh protected files list
        refreshProtectedList: function() {
            console.log('Refreshing protected files list...');
            P.setLoading(true);

            $.ajax({
                url: '/plugins/par2protect/scripts/tasks/list.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    console.log('Protected files response:', response);
                    if (response.success) {
                        const items = response.items;
                        const $list = $('#protected-files-list');
                        $list.empty(); // Clear existing items

                        if (items.length === 0) {
                            $list.append('<tr><td colspan="8" class="notice">No protected files found</td></tr>');
                        } else {
                            items.forEach(item => {
                                $list.append(`
                                    <tr>
                                        <td><input type="checkbox" class="select-item"></td>
                                        <td>${item.path}</td>
                                        <td>${item.mode}</td>
                                        <td>${item.redundancy}%</td>
                                        <td>${item.size}</td>
                                        <td>${item.status}</td>
                                        <td>${item.protectedDate}</td>
                                        <td>${item.lastVerified}</td>
                                        <td>
                                            <button class="verify-btn" data-path="${item.path}">Verify</button>
                                            <button class="remove-btn" data-path="${item.path}">Remove</button>
                                        </td>
                                    </tr>
                                `);
                            });
                        }
                    } else {
                        console.error('Error in response:', response.error);
                        $('#error-display').text('Error: ' + response.error).show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to refresh protected files list:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    $('#error-display').text('Failed to refresh protected files list: ' + error).show();
                },
                complete: function() {
                    P.setLoading(false);
                }
            });
        },

        // Filter list by status
        filterByStatus: function(status) {
            console.log('Filtering by status:', status);
            const rows = $('#protected-files-list tr');
            
            if (status === 'all') {
                rows.show();
            } else {
                rows.each(function() {
                    const rowStatus = $(this).find('td:nth-child(6)').text().toLowerCase();
                    $(this).toggle(rowStatus === status.toLowerCase());
                });
            }
        },

        // Filter list by mode
        filterByMode: function(mode) {
            console.log('Filtering by mode:', mode);
            const rows = $('#protected-files-list tr');
            
            if (mode === 'all') {
                rows.show();
            } else {
                rows.each(function() {
                    const rowMode = $(this).find('td:nth-child(3)').text().toLowerCase();
                    $(this).toggle(rowMode === mode.toLowerCase());
                });
            }
        },

        // Toggle all checkboxes
        toggleSelectAll: function(checkbox) {
            console.log('Toggling all checkboxes:', checkbox.checked);
            $('.select-item').prop('checked', checkbox.checked);
            this.updateSelectedButtons();
        },

        // Update selected buttons state
        updateSelectedButtons: function() {
            const hasSelected = $('.select-item:checked').length > 0;
            $('#removeSelectedBtn, #verifySelectedBtn').prop('disabled', !hasSelected);
        },

        // Verify selected items
        verifySelected: function(singlePath) {
            console.log('verifySelected called with path:', singlePath);
            let paths = [];
            
            if (singlePath) {
                console.log('Single path mode');
                paths.push(singlePath);
            } else {
                console.log('Multiple selection mode');
                $('.select-item:checked').each(function() {
                    const path = $(this).closest('tr').find('td:nth-child(2)').text();
                    console.log('Adding selected path:', path);
                    paths.push(path);
                });
            }

            if (paths.length === 0) {
                console.log('No paths selected');
                swal('Error', 'Please select items to verify', 'error');
                return;
            }

            console.log('Paths to verify:', paths);
            P.setLoading(true);

            const requestData = {
                targets: paths
            };
            console.log('Sending verification request with data:', requestData);

            $.ajax({
                url: '/plugins/par2protect/scripts/tasks/verify.php',
                method: 'POST',
                data: requestData,
                dataType: 'json',
                success: function(response) {
                    console.log('Raw verification response:', response);
                    try {
                        const data = typeof response === 'string' ? JSON.parse(response) : response;
                        console.log('Parsed verification data:', data);
                        
                        if (data.success) {
                            const stats = data.stats || {};
                            console.log('Verification stats:', stats);
                            const message = `Verification completed:\n` +
                                `Verified: ${stats.verified_files || 0}\n` +
                                `Skipped: ${stats.skipped_files || 0}\n` +
                                `Failed: ${stats.failed_files || 0}` +
                                (stats.errors && stats.errors.length > 0 ? `\n\nErrors:\n${stats.errors.join('\n')}` : '');
                            
                            console.log('Success message:', message);
                            addNotice('Verification completed');
                            swal({
                                title: 'Verification Complete',
                                text: message,
                                type: stats.failed_files > 0 ? 'warning' : 'success'
                            });
                            list.refreshProtectedList();
                        } else {
                            console.error('Verification failed:', data.error);
                            swal('Error', data.error || 'Failed to start verification', 'error');
                        }
                    } catch (e) {
                        console.error('Error processing verification response:', e, 'Response was:', response);
                        swal('Error', 'Error processing verification response: ' + e.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Verification request failed:', {
                        status: status,
                        statusText: xhr.statusText,
                        error: error,
                        response: xhr.responseText,
                        headers: xhr.getAllResponseHeaders()
                    });
                    let errorMessage = 'Failed to start verification';
                    try {
                        if (xhr.responseText) {
                            console.log('Trying to parse error response:', xhr.responseText);
                            const response = JSON.parse(xhr.responseText);
                            if (response.error) {
                                errorMessage = response.error;
                            }
                        }
                    } catch (e) {
                        console.error('Error parsing error response:', e);
                    }
                    console.error('Final error message:', errorMessage);
                    swal('Error', errorMessage, 'error');
                },
                complete: function() {
                    P.setLoading(false);
                }
            });
        },

        // Remove selected protections
        removeSelectedProtections: function(singlePath) {
            console.log('Removing selected protections');
            let paths = [];
            
            if (singlePath) {
                paths.push(singlePath);
            } else {
                $('.select-item:checked').each(function() {
                    paths.push($(this).closest('tr').find('td:nth-child(2)').text());
                });
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
                    $.ajax({
                        url: '/plugins/par2protect/scripts/tasks/remove.php',
                        method: 'POST',
                        data: {
                            paths: JSON.stringify(paths)
                        },
                        success: function(response) {
                            if (response.success) {
                                addNotice('Protection removed for selected items');
                                list.refreshProtectedList();
                            } else {
                                swal('Error', response.error || 'Failed to remove protection', 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Remove protection failed:', error);
                            swal('Error', 'Failed to remove protection', 'error');
                        },
                        complete: function() {
                            P.setLoading(false);
                        }
                    });
                }
            });
        },

        // Setup event listeners
        setupEventListeners: function() {
            // Handle verify button clicks
            $(document).on('click', '.verify-btn', function(e) {
                e.preventDefault();
                const path = $(this).data('path');
                console.log('Verify button clicked for path:', path);
                list.verifySelected(path);
            });

            // Handle remove button clicks
            $(document).on('click', '.remove-btn', function(e) {
                e.preventDefault();
                const path = $(this).data('path');
                list.removeSelectedProtections(path);
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
        }
    };

    // Add list methods to Par2Protect
    P.list = list;

    // Initialize when document is ready
    $(document).ready(function() {
        if (document.querySelector('.protected-files-list')) {
            P.list.setupEventListeners();
            P.list.refreshProtectedList();
        }
    });

})(window.Par2Protect);