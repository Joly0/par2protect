// Settings Page Functionality

(function(P) {
    // Settings methods
    const settings = {
        // Initialize settings
        init: function() {
            // P.logger.info('Settings page initialized');

            // Initialize file tree pickers for default extensions
            this.initFileTreePickers();

            // Initialize custom file extensions functionality
            this.initCustomFileExtensions();
            
            // Add form submission handler
            $('form').on('submit', function(e) {
                // Show loading spinner
                P.setLoading(true);
                
                // Get the progressFrame where form results are displayed
                const progressFrame = window.frames['progressFrame'];
                
                // Set a minimum delay before checking for results
                const minDelay = 100; // ms
                
                // Set a maximum timeout in case we never detect the success message
                const maxTimeout = 2000; // ms
                
                // Track if we've already handled the reload
                let reloadHandled = false;
                
                // Function to reload the page
                const reloadPage = function() {
                    if (!reloadHandled) {
                        reloadHandled = true;
                        window.location.href = window.location.pathname;
                    }
                };
                
                // Set up a timeout as a fallback
                const timeoutId = setTimeout(function() {
                    reloadPage();
                }, maxTimeout);
                
                // Function to check if settings were saved
                const checkSettingsSaved = function() {
                    try {
                        if (progressFrame &&
                            progressFrame.document &&
                            progressFrame.document.body &&
                            progressFrame.document.body.innerText) {
                            
                            const frameContent = progressFrame.document.body.innerText;
                            
                            if (frameContent.includes('Settings saved successfully')) {
                                clearTimeout(timeoutId);
                                reloadPage();
                            } else if (frameContent.includes('Error:')) {
                                // Hide loading spinner on error
                                P.setLoading(false);
                                P.logger.error('Error detected in save response:', { frameContent });
                                clearTimeout(timeoutId);
                                // Don't reload on error so user can see the error message
                            }
                        }
                    } catch (err) {
                        P.logger.error('Error checking settings saved status:', { error: err });
                    }
                };
                
                // Start checking after minimum delay
                setTimeout(function() {
                    // Set up interval to check regularly
                    const checkInterval = setInterval(function() {
                        checkSettingsSaved();
                        
                        // Clear interval if reload has been handled
                        if (reloadHandled) {
                            clearInterval(checkInterval);
                        }
                    }, 50); // Check every 50ms
                    
                    // Also clear interval after max timeout
                    setTimeout(function() {
                        clearInterval(checkInterval);
                    }, maxTimeout);
                }, minDelay);
                
                // Continue with normal form submission
                return true;
            });
        },
        
        // Initialize custom file extensions inputs
        initCustomFileExtensions: function() {
            // Get current custom extensions
            let customExtensions = {};
            try {
                customExtensions = JSON.parse($('#all_custom_extensions').val() || '{}');
            } catch (e) {
                P.logger.error('Error parsing custom extensions:', { error: e });
                customExtensions = {};
            }
            
            // Initialize P.config.fileCategories if needed
            P.config.fileCategories = P.config.fileCategories || {};
            
            // Handle file type select button clicks
            $('.file-type-select').on('click', function() {
                const category = $(this).data('category');
                const selectElement = $(`#default_ext_${category}`);
                const selectedExtensions = $(this).data('selected').split(',');
                
                // Create dialog content with checkboxes
                let dialogContent = '<div class="extension-dialog">';
                
                // Get all options from the select
                const options = selectElement.find('option');
                options.each(function() {
                    const ext = $(this).val();
                    const isChecked = selectedExtensions.includes(ext);
                    dialogContent += `
                        <div class="extension-checkbox">
                            <input type="checkbox" id="checkbox_${category}_${ext}" 
                                   data-category="${category}" data-extension="${ext}" 
                                   ${isChecked ? 'checked' : ''}>
                            <label for="checkbox_${category}_${ext}">${ext}</label>
                        </div>
                    `;
                });
                
                dialogContent += '</div>';
                
                // Show the dialog using SweetAlert
                swal({
                    title: `Select Default Extensions for ${category}`,
                    html: dialogContent,
                    showCancelButton: true,
                    confirmButtonText: 'Save',
                    cancelButtonText: 'Cancel',
                    onOpen: function() {
                        // Add event listeners to checkboxes
                        $('.extension-checkbox input').on('change', function() {
                            const ext = $(this).data('extension');
                            const cat = $(this).data('category');
                            const isChecked = $(this).prop('checked');
                            
                            // Update the select element
                            $(`#default_ext_${cat} option[value="${ext}"]`).prop('selected', isChecked);
                        });
                    }
                }).then(function(result) {
                    if (result.value) {
                        // User clicked Save
                        // Get all selected extensions
                        const selectedOptions = selectElement.find('option:selected');
                        const extensions = [];
                        
                        selectedOptions.each(function() {
                            extensions.push($(this).val());
                        });
                        
                        // Update the button's data-selected attribute
                        $(`.file-type-select[data-category="${category}"]`).data('selected', extensions.join(','));
                        
                        // Update the hidden field with all default extensions
                        settings.updateDefaultExtensionsField();
                        
                        // P.logger.info(`Updated default extensions for ${category}`);
                        P.showNotification(`Default extensions for ${category} updated`, 'success');
                    }
                });
            });
            
            // Handle form submission to process custom extensions
            $('form').on('submit', function() {
                // Process all custom extension fields
                $('.settings-group input[id^="custom_ext_"]').each(function() {
                    const inputField = $(this);
                    const category = inputField.attr('id').replace('custom_ext_', '');
                    const value = inputField.val().trim();
                    
                    // Process extensions
                    const extensions = settings.processExtensions(value);
                    
                    // Update custom extensions
                    if (!customExtensions[category]) {
                        customExtensions[category] = [];
                    }
                    
                    // Set the extensions
                    customExtensions[category] = extensions;
                });
                
                // Update the hidden field
                $('#all_custom_extensions').val(JSON.stringify(customExtensions));
                
                // Update default extensions field
                settings.updateDefaultExtensionsField();
                
                // Continue with form submission
                return true;
            });
            
            // Update the hidden field with all default extensions on page load
            this.updateDefaultExtensionsField();
        },
        
        // Initialize file tree pickers for default extensions
        initFileTreePickers: function() {
            $('.filetree-input').each(function() {
                var $input = $(this);
                var category = $input.data('category');
                var extensions = $input.data('extensions');
                var hiddenInput = $(`#default_ext_${category}`);
                var bgcolor = hiddenInput.data('bgcolor');
                
                // Create dropdown container if it doesn't exist
                var dropdownId = `ext_dropdown_${category}`;
                
                // Create a span to display selected extensions if it doesn't exist
                var selectedExtSpanId = `selected_ext_${category}`;
                if ($(`#${selectedExtSpanId}`).length === 0) {
                    // Add the selected extensions display after the default extensions dd element
                    // Log for debugging
                    console.log('Adding selected extensions display for', category);
                    // Create the element with clear positioning and display
                    $input.closest('.extension-picker-container').append($('<div>').attr('id', selectedExtSpanId).addClass('selected-extensions').css({'display': 'block', 'clear': 'both', 'margin-top': '5px', 'font-size': '0.9em', 'color': '#666'}));
                }
                if ($(`#${dropdownId}`).length === 0) {
                    var $dropdown = $('<div>')
                        .attr('id', dropdownId)
                        .addClass('extension-dropdown')
                        .css('background', bgcolor)
                        .hide();
                    
                    // Add header
                    $dropdown.append(
                        $('<div>')
                            .addClass('extension-dropdown-header')
                            .css('background', bgcolor)
                            .text(`Select Default Extensions for ${category}`)
                    );
                    
                    // Add extension list
                    var $extList = $('<div>').addClass('extension-list');
                    
                    // Add checkboxes for each extension
                    $.each(extensions, function(i, ext) {
                        var $item = $('<div>').addClass('extension-item');
                        var $checkbox = $('<input>')
                            .attr('type', 'checkbox')
                            .attr('id', `ext_${category}_${ext}`)
                            .attr('data-extension', ext)
                            .addClass('extension-checkbox');
                        
                        var $label = $('<label>')
                            .attr('for', `ext_${category}_${ext}`)
                            .text(ext);
                        
                        $item.append($checkbox, $label);
                        $extList.append($item);
                    });
                    
                    $dropdown.append($extList);
                    
                    // Add buttons
                    var $buttons = $('<div>').addClass('extension-buttons');
                    
                    var $closeBtn = $('<button>')
                        .attr('type', 'button')
                        .addClass('extension-close-btn')
                        .text('Close')
                        .on('click', function() {
                            // Log that extensions were updated
                            // P.logger.info(`Updated default extensions for ${category}`);
                            
                            // Hide dropdown
                            $dropdown.hide();
                        });
                    
                    $buttons.append($closeBtn);
                    $dropdown.append($buttons);
                    
                    // Add dropdown directly after the input for proper positioning
                    $input.after($dropdown);
                }
                
                // Position the dropdown relative to the container
                function positionDropdown() {
                    var inputHeight = $input.outerHeight();
                    var inputWidth = $input.outerWidth();
                    
                    $(`#${dropdownId}`).css({
                        'position': 'absolute',
                        'top': '28px',
                        'left': 0,
                        'width': inputWidth
                    });
                }
                
                // Set up outside click handler
                $(document).mouseup(function(e) {
                    var container = $(`#${dropdownId}`);
                    var input = $input;
                    
                    // If the target of the click isn't the container, the input, nor a descendant of the container
                    if (!container.is(e.target) && 
                        container.has(e.target).length === 0 && 
                        !input.is(e.target)) {
                        container.hide();
                    }
                });
                
                // Toggle dropdown on input click
                $input.on('click', function() {
                    var $dropdown = $(`#${dropdownId}`);
                    
                    // Update checkbox states based on current selection
                    var selectedExtensions = hiddenInput.val().split('\n').filter(Boolean);
                    $dropdown.find('.extension-checkbox').each(function() {
                        const $checkbox = $(this);
                        var ext = $checkbox.data('extension');
                        $checkbox.prop('checked', selectedExtensions.includes(ext));
                        
                        // Add change event to each checkbox for dynamic updates
                        $checkbox.off('change').on('change', function() {
                            // Get all selected extensions
                            var newSelectedExtensions = [];
                            $(`#${dropdownId} .extension-checkbox:checked`).each(function() {
                                newSelectedExtensions.push($(this).data('extension'));
                            });
                            
                            // Update the hidden input
                            hiddenInput.val(newSelectedExtensions.join('\n'));
                            
                            // Update the display input with X/Y format
                            const totalExtensions = extensions.length;
                            $input.val(`${newSelectedExtensions.length}/${totalExtensions} extensions selected`);

                            // Update the selected extensions display
                            var $selectedExtSpan = $(`#${selectedExtSpanId}`);
                            if (newSelectedExtensions.length > 0) {
                                $selectedExtSpan.text(`Selected: ${newSelectedExtensions.join(', ')}`);
                                $selectedExtSpan.show();
                            } else {
                                $selectedExtSpan.hide();
                            }
                            
                            // If no extensions are selected, show empty input
                            if (newSelectedExtensions.length === 0) {
                                $input.val('');
                            }
                            
                            settings.updateDefaultExtensionsField();
                        });
                    });
                    
                    // Position the dropdown before showing it
                    positionDropdown();
                    
                    // Show/hide dropdown
                    if ($dropdown.is(':visible')) {
                        $dropdown.hide();
                    } else {
                        // Hide any other open dropdowns
                        $('.extension-dropdown').hide();
                        $dropdown.show();
                        
                        // Scroll the dropdown into view
                        setTimeout(function() {
                            $dropdown[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }, 50);
                    }
                });
                
                // Set initial display value
                const initialSelected = hiddenInput.val().split('\n').filter(Boolean);
                const totalExtensions = extensions.length;
                if (initialSelected.length > 0) {
                    $input.val(`${initialSelected.length}/${totalExtensions} extensions selected`);
                }
                
                // Initialize the selected extensions display
                var $selectedExtSpan = $(`#${selectedExtSpanId}`);
                if (initialSelected.length > 0) {
                    $selectedExtSpan.text(`Selected: ${initialSelected.join(', ')}`);
                    $selectedExtSpan.show();
                } else {
                    $selectedExtSpan.hide();
                }
            });
        },

        // Update the hidden field with all default extensions
        updateDefaultExtensionsField: function() {
            const defaultExtensions = {};
            
            // Process all default extension hidden inputs
            $('input[id^="default_ext_"][type="hidden"]').each(function() {
                const input = $(this);
                const category = input.attr('id').replace('default_ext_', '');
                const value = input.val();
                
                // Split by newlines and filter out empty values
                defaultExtensions[category] = value.split('\n').filter(Boolean);
            });
            
            // Update the hidden field
            $('#all_default_extensions').val(JSON.stringify(defaultExtensions));
        },
        
        // Process extensions input
        processExtensions: function(input) {
            // Split by commas, trim each value, and filter out empty values
            const extensions = input.split(',')
                .map(ext => ext.trim().toLowerCase())
                .filter(ext => ext !== '');
            
            // Remove any leading dots
            return extensions.map(ext => 
                ext.startsWith('.') ? ext.substring(1) : ext
            );
        }
    };

    // Add settings methods to Par2Protect
    P.settings = settings;

    // Initialize when document is ready
    $(document).ready(function() {
        // Initialize settings page
        P.settings.init();
    });

})(window.Par2Protect || (window.Par2Protect = {}));