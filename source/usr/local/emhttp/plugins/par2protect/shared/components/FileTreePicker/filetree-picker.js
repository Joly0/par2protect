/**
 * Enhanced FileTree Picker for Unraid plugins
 * This script handles the checkbox functionality for the filetree picker
 */

// Function to initialize the file tree picker
function initializeFileTreePicker(inputId, rootPath, foldersOnly) {
    const $input = $('#' + inputId);
    const $list = $('#' + inputId + '-list');
    const $container = $('.filetree-picker-container');
    const $treeContainer = $('#' + inputId + '-tree-container');
    // Track tree visibility state explicitly
    let isTreeVisible = false;
        
    // Initialize with any existing values
    updatePathsList($input, $list);
    
    // Ensure the tree container is initially hidden
    $treeContainer.hide();
    $treeContainer.addClass('force-hidden');
    // Add folders-only class if needed
    if (foldersOnly) {
        $treeContainer.addClass('folders-only-mode');
    }
    
    // Variable to track if we've attached the document click handler
    let documentClickHandlerAttached = false;
    
    // Function to handle document clicks for closing the tree
    function handleDocumentClick(e) {
        // If the click target is within the filetree container, the list, or the container, do nothing
        // Check if click is inside elements we want to keep open
        if ($(e.target).closest($treeContainer).length || 
            $(e.target).closest($list).length || 
            $(e.target).closest($container).length ||
            $(e.target).closest('.ui-dialog').length) {
            return;
        }
        
        // Otherwise, hide the tree
        // Force hide with multiple methods
        try {
            // Try multiple approaches to hide the element
            $treeContainer.hide().css('display', 'none');
            $treeContainer.addClass('force-hidden');
            
            // Force a reflow
            void $treeContainer[0].offsetHeight;
        } catch (e) {
            console.error('Error hiding tree:', e);
        }
        
        isTreeVisible = false;
        
        // Adjust content height for dialog only (filetree closed)
        if (window.Par2Protect && window.Par2Protect.adjustContentForFiletree) {
            window.Par2Protect.adjustContentForFiletree(false);
        }
        
        // Remove the document click handler
        $(document).off('click.filetreepicker');
        documentClickHandlerAttached = false;
    }
    
    // Make both the list and the container clickable to toggle the file tree
    $list.on('click', function(e) {
        // Don't trigger if clicking on a remove button 
        if ($(e.target).hasClass('remove-path')) {
            return;
        }
        
        toggleFileTree(e);
    });
    
    // Also make the empty list message clickable
    // Use a direct selector to avoid duplicate event triggering
    $('.empty-list-message').on('click', function(e) {
        toggleFileTree(e);
    });
    
    // Function to toggle the file tree visibility
    function toggleFileTree(e) {
        // Prevent event from being handled multiple times
        if (e && e.stopPropagation) {
            e.stopPropagation();
            e.preventDefault();
        }
        
        // Toggle based on our tracked state, not the DOM state
        if (isTreeVisible) {
            $treeContainer.hide().addClass('force-hidden');
            isTreeVisible = false;
            
            // Remove the document click handler
            $(document).off('click.filetreepicker');
            documentClickHandlerAttached = false;
            
            // Adjust content height for dialog only (filetree closed)
            if (window.Par2Protect && window.Par2Protect.adjustContentForFiletree) {
                window.Par2Protect.adjustContentForFiletree(false);
            }
        } else {
            // Force show with multiple methods
            try {
                $treeContainer.show();
                $treeContainer.removeClass('force-hidden');
                $treeContainer.css('display', 'block');
                // Direct DOM manipulation
                $treeContainer[0].style.display = 'block';
                
                // Force a reflow
                void $treeContainer[0].offsetHeight;
            } catch (e) {
                console.error('Error showing tree:', e);
            }
            
            isTreeVisible = true; 
            
            // Adjust content height for filetree (filetree open)
            if (window.Par2Protect && window.Par2Protect.adjustContentForFiletree) {
                window.Par2Protect.adjustContentForFiletree(true);
            }
            
            // If the tree is not yet initialized, initialize it
            if ($treeContainer.children().length === 0) {
                const $fileTree = $('<div class="fileTree"></div>').appendTo($treeContainer);
                // console.log('Initializing file tree with root:', rootPath);
                // Initialize the file tree
                $fileTree.fileTree({
                    // Enable multiSelect to use the built-in checkbox functionality
                    multiSelect: true,
                    multiFolder: true,
                    root: rootPath,
                    folderEvent: 'click',
                    expandSpeed: 200,
                    collapseSpeed: 200,
                    loadMessage: 'Loading...',
                    // Only show folders if foldersOnly is true
                    showFiles: !foldersOnly,
                    // Enable checkboxes
                    checkboxes: true
                }, function(file) {
                    // This is the file/folder selection callback
                    // We don't need to do anything here as we're handling selection via checkboxes
                });
                
                // Process the tree after initialization
                processFileTree($fileTree, foldersOnly);
            }
            
            // Add the document click handler after a short delay
            // This prevents the handler from being triggered by the current click
            if (!documentClickHandlerAttached) {
                // First remove any existing handlers to avoid duplicates
                $(document).off('click.filetreepicker');
                
                setTimeout(function() {
                    // Use namespaced event to ensure we can properly remove it later
                    $(document).on('click.filetreepicker', handleDocumentClick);
                    documentClickHandlerAttached = true;
                }, 100);
            }
        }
    }
    
    // Also handle escape key to close the tree
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $treeContainer.is(':visible')) {
            $treeContainer.hide().addClass('force-hidden');
            isTreeVisible = false;
            
            // Remove the document click handler
            $(document).off('click.filetreepicker');
            documentClickHandlerAttached = false;
            
            // Adjust content height for dialog only (filetree closed)
            if (window.Par2Protect && window.Par2Protect.adjustContentForFiletree) {
                window.Par2Protect.adjustContentForFiletree(false);
            }
            
            e.preventDefault();
        }
    });
    
    // Add event listener for checkbox clicks in the file tree
    $treeContainer.on('click', 'input[type="checkbox"]', function(e) {
        e.stopPropagation();
        const $checkbox = $(this);
        const $li = $checkbox.closest('li');
        
        // Only process directory checkboxes
        if (!$li.hasClass('directory')) return;
        
        // console.log('Checkbox clicked:', $checkbox.is(':checked'), $li.find('> a').attr('rel'));
        if ($checkbox.is(':checked')) {
            // Disable and uncheck checkboxes in child directories
            const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
            if ($childCheckboxes.length > 0) {
                $childCheckboxes.prop('checked', false);
                $childCheckboxes.prop('disabled', true);
            }
            
            // Add a class to the li element to mark it as checked
            // This will help us identify it when the folder is expanded
            $li.addClass('parent-checked');
            
            // Get the path from the link
            const path = $li.find('> a').attr('rel');
            
            // Add the path to the input
            let currentPaths = $input.val().split('\n').filter(Boolean);
            if (!currentPaths.includes(path)) {
                currentPaths.push(path);
                $input.val(currentPaths.join('\n')).change();
                
                // Update the paths list
                updatePathsList($input, $list);
            }
        } else {
            // Enable checkboxes in child directories
            const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
            if ($childCheckboxes.length > 0) {
                $childCheckboxes.prop('disabled', false);
            }
            
            // Remove the class from the li element
            $li.removeClass('parent-checked');
            
            // Get the path from the link
            const path = $li.find('> a').attr('rel');
            
            // Remove the path from the input
            let currentPaths = $input.val().split('\n').filter(Boolean);
            currentPaths = currentPaths.filter(p => p !== path);
            $input.val(currentPaths.join('\n')).change();
            
            // Update the paths list
            updatePathsList($input, $list);
        }
    });
    
    // We're now using the native checkboxes directly, so no need for additional handlers
}

// Function to process the file tree - handle checkbox behavior
function processFileTree($fileTree, foldersOnly) {
    // console.log('Processing file tree, foldersOnly:', foldersOnly);
    
    // Use a slightly longer timeout to ensure the DOM is ready
    setTimeout(function() {
        // If folders only, hide all file items
        if (foldersOnly) {
            $fileTree.find('li.file').hide();
        }
        
        // Check for any checked parent checkboxes and disable (but don't remove) their children
        $fileTree.find('li.directory').each(function() {
            const $li = $(this);
            const $checkbox = $li.find('> input[type="checkbox"]');
            const path = $li.find('> a').attr('rel');
            
            if ($checkbox.length > 0 && $checkbox.is(':checked')) {
                // console.log('Found checked directory checkbox for:', path);
                // Disable all child checkboxes
                const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
                if ($childCheckboxes.length > 0) {
                    // console.log('Disabling', $childCheckboxes.length, 'child checkboxes for', path);
                    $childCheckboxes.prop('checked', false);
                    $childCheckboxes.prop('disabled', true);
                }
                
                // Add the parent-checked class if it's not already there
                if (!$li.hasClass('parent-checked')) {
                    $li.addClass('parent-checked');
                }
            }
        });
        
        // Also check for parent-checked class without checked checkbox
        // This can happen if the checkbox state and class get out of sync
        $fileTree.find('li.directory.parent-checked').each(function() {
            const $li = $(this);
            const $checkbox = $li.find('> input[type="checkbox"]');
            
            if (!$checkbox.is(':checked')) {
                // The class indicates it should be checked, but it's not
                // This is an inconsistent state, so let's fix it
                // console.log('Found inconsistent state: parent-checked class but checkbox not checked');
                $checkbox.prop('checked', true);
                
                // Disable all child checkboxes
                const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
                if ($childCheckboxes.length > 0) {
                    $childCheckboxes.prop('checked', false);
                    $childCheckboxes.prop('disabled', true);
                }
            }
        });
    }, 100); // Longer timeout to ensure DOM is fully ready
}

// Function to update the paths list from the input value
function updatePathsList($input, $list) {
    const paths = $input.val().split('\n').filter(Boolean);
    const oldPathCount = $list.find('.path-item').length;
    
    // Update parent-checked classes for all paths
    // This ensures that if a path is added programmatically, the checkboxes are properly disabled
    $('li.directory').removeClass('parent-checked');
    paths.forEach(path => {
        $('li.directory > a[rel="' + path + '"]').parent().addClass('parent-checked');
    });
    const newPathCount = paths.length;
    
    // Add a cursor pointer to the list to indicate it's clickable
    $list.css('cursor', 'pointer');
    
    if (paths.length === 0) {
        $list.html('<div class="empty-list-message">No paths selected. Click here to select paths.</div>');
        
        // If paths were removed, adjust content height
        if (oldPathCount > 0 && window.Par2Protect && window.Par2Protect.adjustContentForFiletree) {
            // Use the current filetree visibility state
            const isTreeVisible = window.Par2Protect.dashboard && window.Par2Protect.dashboard.isFiletreeOpen;
            window.Par2Protect.adjustContentForFiletree(isTreeVisible);
        }
        
        return;
    }
    
    let html = '';
    paths.forEach(path => {
        html += `
            <div class="path-item" data-path="${path}">
                <span class="path-text">${path}</span>
                <button type="button" class="remove-path" title="Remove this path">×</button>
            </div>
        `;
    });
    
    $list.html(html);
    
    // Always adjust content height when paths change
    if (window.Par2Protect && window.Par2Protect.adjustContentForFiletree) {
        // Use the current filetree visibility state
        const isTreeVisible = window.Par2Protect.dashboard && window.Par2Protect.dashboard.isFiletreeOpen;
        window.Par2Protect.adjustContentForFiletree(isTreeVisible);
    }
    
    // Add event listeners for remove buttons
    $list.find('.remove-path').on('click', function(e) {
        e.stopPropagation(); // Prevent the list click handler from firing
        
        const $item = $(this).closest('.path-item');
        const path = $item.data('path');
        const $input = $('#' + $list.attr('id').replace('-list', ''));
        
        // Remove from the input value
        const currentPaths = $input.val().split('\n').filter(Boolean);
        const updatedPaths = currentPaths.filter(p => p !== path);
        $input.val(updatedPaths.join('\n')).change();
        
        // Update the list
        updatePathsList($input, $list);
        
        // Also uncheck the corresponding checkbox in the filetree
        $('li.directory > input[type="checkbox"]').each(function() {
            const $checkbox = $(this);
            const $li = $checkbox.closest('li');
            const checkboxPath = $li.find('> a').attr('rel');
            
            if (checkboxPath === path) {
                $checkbox.prop('checked', false);
                
                // Re-enable child checkboxes
                const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
                if ($childCheckboxes.length > 0) {
                    $childCheckboxes.prop('disabled', false);
                }
            }
        });
    });
}

// Override the jQuery FileTree checkbox change event handler
$(document).ready(function() {
    // Add direct handler for folder clicks to ensure we catch expansions
    $(document).on('click', '.fileTree li.directory > a', function() {
        const $a = $(this);
        const $li = $a.parent();
        
        // Check if this folder is being expanded (not collapsed)
        // We can tell by checking if it has the 'expanded' class after a short delay
        setTimeout(function() {
            if ($li.hasClass('expanded')) {
                // console.log('Folder clicked and expanded:', $a.attr('rel'));
                
                // Check if parent checkbox is checked
                const $checkbox = $li.find('> input[type="checkbox"]');
                if ($checkbox.length > 0 && $checkbox.is(':checked')) {
                    // console.log('Parent checkbox is checked, disabling child checkboxes');
                    
                    // Disable all child checkboxes
                    const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
                    $childCheckboxes.prop('checked', false);
                    $childCheckboxes.prop('disabled', true);
                }
            }
        }, 100); // Short delay to allow the expanded class to be applied
    });
    
    // Add a global function to force hide the tree for debugging
    window.forceHideTree = function(inputId) {
        const $treeContainer = $('#' + inputId + '-tree-container');        
        if ($treeContainer.length) {
            try {
                $treeContainer.hide();
                $treeContainer.addClass('force-hidden');
                $treeContainer.css('display', 'none');
                $treeContainer[0].style.display = 'none';
                
                // Adjust content height for dialog only (filetree closed)
                if (window.Par2Protect && window.Par2Protect.adjustContentForFiletree) {
                    window.Par2Protect.adjustContentForFiletree(false);
                }
            } catch (e) {
                console.error('Error in forceHideTree:', e);
            }
        }
    };
    
    // Handle folder expansion to hide files in folders-only mode
    // Listen for both expand and expanded events to catch all cases
    $(document).on('filetreeexpand filetreeexpanded', function(e, data) {
        // console.log('Filetree event triggered:', e.type, data);
        
        // Only proceed if we have valid data
        if (!data || !data.li) {
            // console.log('Invalid data for filetree event');
            return;
        }
        
        // Check if we're in folders-only mode
        const $treeContainer = $(data.li).closest('.filetree-picker-container');
        if (!$treeContainer.length) {
            // console.log('Could not find filetree-picker-container, trying alternate selectors');
            // Try other possible container selectors
            const $altContainer = $(data.li).closest('.fileTree').parent();
            // console.log('Alternative container found:', $altContainer.length > 0);
        }
        
        const foldersOnly = $treeContainer.hasClass('folders-only-mode');
        
        // Adjust content height for filetree (filetree open)
        if (window.Par2Protect && window.Par2Protect.adjustContentForFiletree) {
            window.Par2Protect.adjustContentForFiletree(true);
        }
        
        if (foldersOnly) {
            // Hide all file items
            $(data.li).find('li.file').hide();
        }
        // Get the expanded li element
        const $expandedLi = $(data.li);
        
        // console.log('Folder expanded:', $expandedLi.find('> a').attr('rel'));
        // console.log('Has parent-checked class:', $expandedLi.hasClass('parent-checked'));
        // console.log('Parent checkbox checked:', $expandedLi.find('> input[type="checkbox"]').is(':checked'));
        
        // CRITICAL FIX: Directly check if the checkbox is checked
        const $checkbox = $expandedLi.find('> input[type="checkbox"]');
        if ($checkbox.length > 0 && $checkbox.is(':checked')) {
            // console.log('IMPORTANT: Parent checkbox is checked, disabling all child checkboxes');
            
            // Immediately disable all checkboxes in the expanded folder
            const $allChildCheckboxes = $expandedLi.find('ul li input[type="checkbox"]');
            $allChildCheckboxes.prop('checked', false);
            $allChildCheckboxes.prop('disabled', true);
            
            // Force a reflow to ensure the changes take effect
            void $expandedLi[0].offsetHeight;
        }
        
        // Function to recursively disable all child checkboxes
        function disableChildCheckboxes($element) {
            // First level children
            const $childCheckboxes = $element.find('> ul > li > input[type="checkbox"]');
            if ($childCheckboxes.length > 0) {
                $childCheckboxes.prop('checked', false);
                $childCheckboxes.prop('disabled', true);
                
                // Now recursively process each child that might have its own children
                $element.find('> ul > li.directory').each(function() {
                    disableChildCheckboxes($(this));
                });
            }
        }
        
        // Check if the parent checkbox is checked
        const $parentCheckbox = $expandedLi.find('> input[type="checkbox"]');
        if ($parentCheckbox.length > 0 && $parentCheckbox.is(':checked')) {
            // If parent is checked, disable all child checkboxes recursively
            disableChildCheckboxes($expandedLi);
            // console.log('Disabled child checkboxes for checked parent');
        }
        
        // Also check all parent folders to see if any of them are checked
        // If so, this expanded folder's checkboxes should be disabled
        let $parent = $expandedLi.parent().closest('li.directory');
        while ($parent.length > 0) {
            const $parentCheck = $parent.find('> input[type="checkbox"]');
            if ($parentCheck.length > 0 && $parentCheck.is(':checked')) {
                // A parent folder is checked, so disable all checkboxes in this expanded folder
                const $allChildCheckboxes = $expandedLi.find('input[type="checkbox"]');
                $allChildCheckboxes.prop('checked', false);
                $allChildCheckboxes.prop('disabled', true);
                // console.log('Disabled checkboxes because a parent folder is checked');
                break;
            }
            $parent = $parent.parent().closest('li.directory');
        }
        
        // Process the expanded folder to fix any remaining checkboxes
        const $fileTree = $expandedLi.closest('.fileTree');
        
        // Use a slightly longer timeout to ensure the DOM is fully updated
        setTimeout(function() {
            processFileTree($fileTree, foldersOnly);
            // console.log('Processed checkboxes after folder expansion');
        }, 50);
    });
    
    // Override the jQuery FileTree checkbox change event handler
    // This prevents the default behavior of checking all child checkboxes
    $(document).on('filetreechecked', function(e, data) {
        // Prevent the default behavior of checking all child checkboxes
        e.stopPropagation();
        
        // Find the li element
        const $li = $(data.li);
        // console.log('filetreechecked event for:', $li.find('> a').attr('rel'));
        
        // CRITICAL FIX: Directly disable all child checkboxes
        // This is the most reliable way to ensure they're disabled
        const $allChildCheckboxes = $li.find('ul li input[type="checkbox"]');
        $allChildCheckboxes.prop('checked', false);
        $allChildCheckboxes.prop('disabled', true);
        
        // Force a reflow to ensure the changes take effect
        void $li[0].offsetHeight;
        
        // console.log('IMPORTANT: Directly disabled all child checkboxes in filetreechecked event');
    });
    // Add a global function to refresh the file tree checkboxes
    window.refreshFileTreeCheckboxes = function() {
        $('.fileTree').each(function() {
            const $tree = $(this);
            const foldersOnly = $tree.closest('.folders-only-mode').length > 0;
            processFileTree($tree, foldersOnly);
            
            // CRITICAL FIX: Find all checked checkboxes and disable their children
            $tree.find('input[type="checkbox"]:checked').each(function() {
                const $checkbox = $(this);
                const $li = $checkbox.closest('li');
                
                // Disable all child checkboxes
                const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
                $childCheckboxes.prop('checked', false);
                $childCheckboxes.prop('disabled', true);
                
                // console.log('Disabled child checkboxes for checked parent in refresh function');
            });
        });
        // console.log('Refreshed all file tree checkboxes');
    };
    
    // Add a global function to uncheck all checkboxes in the file tree
    window.uncheckAllFileTreeCheckboxes = function() {
        $('.fileTree').each(function() {
            const $tree = $(this);
            
            // Uncheck all checkboxes
            $tree.find('input[type="checkbox"]:checked').each(function() {
                const $checkbox = $(this);
                $checkbox.prop('checked', false);
                
                // Remove parent-checked class
                const $li = $checkbox.closest('li');
                $li.removeClass('parent-checked');
                
                // Enable all child checkboxes that were disabled
                const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
                $childCheckboxes.prop('disabled', false);
            });
        });
        // console.log('Unchecked all file tree checkboxes');
    };
    
    // Call refresh after a short delay to ensure everything is loaded
    setTimeout(function() {
        if (window.refreshFileTreeCheckboxes) {
            window.refreshFileTreeCheckboxes();
        }
    }, 500);
    
    // CRITICAL FIX: Override the jQuery FileTree plugin's expand function
    // This ensures we catch folder expansions directly at the source
    if ($.fn.fileTree && $.fn.fileTree.defaults) {
        // console.log('Attempting to override jQuery FileTree plugin behavior');
        
        // Store the original function
        const originalFileTreeFn = $.fn.fileTree;
        
        // Override the function
        $.fn.fileTree = function(options, file_callback, folder_callback) {
            // console.log('FileTree plugin called with options:', options);
            
            // Call the original function
            const result = originalFileTreeFn.call(this, options, file_callback, folder_callback);
            
            // Add our own handler for folder expansion
            this.on('click', 'li.directory > a', function() {
                const $a = $(this);
                const $li = $a.parent();
                
                // console.log('Direct folder click in overridden plugin:', $a.attr('rel'));
                
                // Use a timeout to allow the folder to expand first
                setTimeout(function() {
                    if ($li.hasClass('expanded')) {
                        // console.log('Folder expanded in overridden plugin:', $a.attr('rel'));
                        
                        // Check if the checkbox is checked
                        const $checkbox = $li.find('> input[type="checkbox"]');
                        if ($checkbox.length > 0 && $checkbox.is(':checked')) {
                            // console.log('Checkbox is checked, disabling child checkboxes in overridden plugin');
                            
                            // Disable all child checkboxes
                            const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
                            $childCheckboxes.prop('checked', false);
                            $childCheckboxes.prop('disabled', true);
                        }
                    }
                }, 200);
            });
            
            return result;
        };
        
        // Copy over any properties from the original function
        $.extend($.fn.fileTree, originalFileTreeFn);
    } else {
        // console.log('jQuery FileTree plugin not found or does not have defaults property');
    }
    
    // CRITICAL FIX: Add a direct event handler for checkbox clicks
    // This ensures that when a checkbox is clicked, all child checkboxes are disabled
    $(document).on('click', '.fileTree input[type="checkbox"]', function() {
        const $checkbox = $(this);
        const $li = $checkbox.closest('li');
        
        // Only process if this is a directory checkbox that was just checked
        if ($li.hasClass('directory') && $checkbox.is(':checked')) {
            // console.log('Direct checkbox click handler: disabling child checkboxes');
            
            // Disable all child checkboxes
            const $childCheckboxes = $li.find('ul li input[type="checkbox"]');
            $childCheckboxes.prop('checked', false);
            $childCheckboxes.prop('disabled', true);
        }
    });
    
    // Add a mutation observer to watch for changes to the DOM
    // This will catch when new elements are added during folder expansion
    try {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    // Check if any of the added nodes are UL elements (folder contents)
                    $(mutation.addedNodes).each(function() {
                        const $node = $(this);
                        if ($node.is('ul')) {
                            // console.log('Mutation observer: New UL element added to DOM');
                            
                            // Find the parent LI element
                            const $parentLi = $node.closest('li.directory');
                            if ($parentLi.length > 0) {
                                // console.log('Found parent LI for new UL:', $parentLi.find('> a').attr('rel'));
                                
                                // Check if the parent checkbox is checked
                                const $parentCheckbox = $parentLi.find('> input[type="checkbox"]');
                                if ($parentCheckbox.length > 0 && $parentCheckbox.is(':checked')) {
                                    // console.log('Parent checkbox is checked, disabling child checkboxes in mutation observer');
                                    
                                    // Disable all child checkboxes
                                    const $childCheckboxes = $node.find('input[type="checkbox"]');
                                    $childCheckboxes.prop('checked', false);
                                    $childCheckboxes.prop('disabled', true);
                                }
                                
                                // Also check all ancestor folders to see if any are checked
                                let $ancestor = $parentLi.parent().closest('li.directory');
                                while ($ancestor.length > 0) {
                                    const $ancestorCheckbox = $ancestor.find('> input[type="checkbox"]');
                                    if ($ancestorCheckbox.length > 0 && $ancestorCheckbox.is(':checked')) {
                                        // console.log('Ancestor checkbox is checked, disabling child checkboxes in mutation observer');
                                        
                                        // Disable all child checkboxes in the current folder
                                        const $childCheckboxes = $node.find('input[type="checkbox"]');
                                        $childCheckboxes.prop('checked', false);
                                        $childCheckboxes.prop('disabled', true);
                                        break;
                                    }
                                    $ancestor = $ancestor.parent().closest('li.directory');
                                }
                            }
                        }
                    });
                }
            });
        });
        
        // Start observing the document with the configured parameters
        observer.observe(document.body, { childList: true, subtree: true });
        // console.log('Mutation observer successfully attached');
    } catch (e) {
        console.error('Error setting up mutation observer:', e);
    }
});