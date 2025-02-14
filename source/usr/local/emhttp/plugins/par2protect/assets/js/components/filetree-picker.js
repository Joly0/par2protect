class PathState {
    constructor() {
        this.paths = [];
        this.input = null;
        this.container = null;
    }

    init(inputId) {
        this.input = $(inputId);
        this.container = this.input.siblings('.selected-paths-container');
        
        // Create columns for the new layout
        this.buttonsColumn = $('<div>').addClass('buttons-column');
        this.contentColumn = $('<div>').addClass('content-column');
        
        // Assemble the structure
        this.container.append(this.buttonsColumn, this.contentColumn);
        
        // Set up scroll synchronization
        this.contentColumn.on('scroll', () => {
            this.buttonsColumn.scrollTop(this.contentColumn.scrollTop());
        });
        
        // Load initial paths
        const initialPaths = this.input.val() ? this.input.val().split('\n').filter(Boolean) : [];
        this.paths = initialPaths;
        this.updateDisplay();
    }

    addPath(path) {
        if (!path || typeof path !== 'string' || path.trim() === '') {
            return false;
        }
        path = path.trim();
        if (!this.paths.includes(path)) {
            this.paths.push(path);
            this.updateDisplay();
            return true;
        }
        return false;
    }

    removePath(path) {
        const index = this.paths.indexOf(path);
        if (index > -1) {
            this.paths.splice(index, 1);
            this.updateDisplay();
            return true;
        }
        return false;
    }

    updateDisplay() {
        // Clear existing content
        this.buttonsColumn.empty();
        this.contentColumn.empty();

        // Add paths
        this.paths.forEach((path, index) => {
            // Create button wrapper and button
            const buttonWrapper = $('<div>').addClass('button-wrapper');
            const button = $('<button>')
                .addClass('remove-path')
                .html('×')
                .on('click', () => {
                    this.removePath(path);
                    return false;
                });
            buttonWrapper.append(button);
            this.buttonsColumn.append(buttonWrapper);

            // Create path item
            const pathItem = $('<div>').addClass('path-item');
            const pathText = $('<div>').addClass('path-text').text(path);
            pathItem.append(pathText);
            this.contentColumn.append(pathItem);
        });
        
        // Update hidden input
        this.input.val(this.paths.join('\n'));
    }
}

class FileTreePicker {
    constructor(inputId) {
        this.inputId = inputId;
        this.pathState = new PathState();
        this.treeElement = $(`#fileTree-${inputId.replace('#', '')}`);
    }

    init() {
        this.initializeFileTree();
        this.setupOutsideClickHandler();
        this.pathState.init(this.inputId);
    }

    initializeFileTree() {
        this.treeElement.fileTree({
            root: '/mnt/user/',
            multiSelect: true,
            folderSelect: true,
            scripts: true,
            expandSpeed: 200
        }, function(file) {
            event.preventDefault();
            event.stopPropagation();
        });

        this.treeElement.on('change', 'input:checkbox', this.handleCheckboxChange.bind(this));
    }

    handleCheckboxChange(event) {
        const checkbox = $(event.target);
        const li = checkbox.closest('li');
        
        if (checkbox.prop('checked')) {
            if (this.hasCheckedParent(li)) {
                checkbox.prop('checked', false);
                return;
            }
            
            if (li.hasClass('directory')) {
                this.handleDirectorySelection(li);
            }
        } else if (li.hasClass('directory')) {
            this.handleDirectoryDeselection(li);
        }
    }

    hasCheckedParent(element) {
        return element.parents('li.directory').find('> input:checkbox:checked').length > 0;
    }

    handleDirectorySelection(directoryElement) {
        this.disableChildCheckboxes(directoryElement);
        
        const observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.addedNodes.length) {
                    this.disableChildCheckboxes($(mutation.target));
                }
            });
        });
        
        observer.observe(directoryElement[0], {
            childList: true,
            subtree: true
        });
        
        directoryElement.data('observer', observer);
    }

    handleDirectoryDeselection(directoryElement) {
        directoryElement.find('li input:checkbox').removeAttr('disabled');
        
        const observer = directoryElement.data('observer');
        if (observer) {
            observer.disconnect();
            directoryElement.removeData('observer');
        }
    }

    disableChildCheckboxes(element) {
        element.find('li input:checkbox').each(function() {
            $(this).prop('checked', false).attr('disabled', 'disabled');
        });
    }

    setupOutsideClickHandler() {
        const ft = this.treeElement.closest('.ft');
        const container = ft.siblings('.selected-paths-container');

        $(document).mouseup(e => {
            const target = $(e.target);
            
            if (!ft.is(target) &&
                !ft.has(target).length &&
                !container.is(target) &&
                !container.has(target).length &&
                !target.is('input[type="checkbox"]')) {
                ft.slideUp('fast');
            }
        });

        // Show file tree when clicking the container
        container.on('click', e => {
            if (!$(e.target).hasClass('remove-path') && !$(e.target).closest('.remove-path').length) {
                ft.slideDown('fast');
            }
        });
    }

    addSelectionToList() {
        const ft = this.treeElement.closest('.ft');
        let addedCount = 0;
        
        // Get all checked items
        const checkedBoxes = ft.find('input:checked');
        
        checkedBoxes.each((_, checkbox) => {
            const li = $(checkbox).closest('li');
            const link = li.find('a').first();
            const path = link.length ? link.attr('rel') : null;
            
            if (path) {
                this.pathState.addPath(path);
                addedCount++;
            }
            
            // Uncheck after processing
            $(checkbox).prop('checked', false);
        });
    }
}

// Initialize when document is ready
$(document).ready(() => {
    if (typeof currentTreeId !== 'undefined') {
        const picker = new FileTreePicker(currentTreeId);
        picker.init();
        
        // Add global function for the "Add to list" button
        window.addSelectionToList = function(button) {
            event.preventDefault();
            event.stopPropagation();
            picker.addSelectionToList();
        };
    }
});