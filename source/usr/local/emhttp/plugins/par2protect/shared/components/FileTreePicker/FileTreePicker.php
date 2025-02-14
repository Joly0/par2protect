<?php
namespace Par2Protect\Components;

require_once "/usr/local/emhttp/webGui/include/Helpers.php";

/**
 * Multi-select Filetree Picker Component for Unraid plugins
 * Location: /usr/local/emhttp/plugins/par2protect/shared/components/FileTreePicker/FileTreePicker.php
 */
class FileTreePicker {
    /**
     * Renders a file tree picker with multi-select capabilities
     *
     * @param string $inputId The ID for the list container element
     * @param string $inputName The name for the hidden input element
     * @param string $currentValue Current selected paths (newline separated)
     * @param bool $required Whether the field is required
     * @param bool $foldersOnly Whether to show only folders (default: false)
     * @return string The HTML for the file tree picker
     */
    public static function render($inputId, $inputName, $currentValue = '', $required = false, $foldersOnly = false) {
        $requiredAttr = $required ? ' required="required"' : '';
        
        // Set attributes for the filetree picker
        $rootPath = '/mnt/user/';
        $folderOnlyAttrs = $foldersOnly ? ' data-pickfolders="true" data-pickfilter="HIDE_FILES_FILTER"' : '';
        
        // Get Unraid theme background color for dynamic styling
        global $display;
        $bgcolor = strstr('white,azure', $display['theme']) ? '#f2f2f2' : '#1c1c1c';
        
        $html = <<<HTML
        <script type="text/javascript" src="/webGui/javascript/jquery.filetree.js"></script>
        <link type="text/css" rel="stylesheet" href="/webGui/styles/jquery.filetree.css">
        <link type="text/css" rel="stylesheet" href="/plugins/par2protect/shared/components/FileTreePicker/filetree-picker.css">
        <script type="text/javascript" src="/plugins/par2protect/shared/components/FileTreePicker/filetree-picker.js"></script>
        <script type="text/javascript">
        $(function() {
            initializeFileTreePicker('{$inputId}', '{$rootPath}', {$foldersOnly});
        });
        </script>

        <div class="filetree-picker-container">
            <!-- Hidden input to store the actual values -->
            <input type="hidden" 
                id="{$inputId}" 
                name="{$inputName}" 
                value="{$currentValue}"
                {$requiredAttr}
            >
            
            <!-- Selected paths list -->
            <div id="{$inputId}-list" class="selected-paths-list" data-pickroot="{$rootPath}" {$folderOnlyAttrs}>
                <div class="empty-list-message">No paths selected. Click here to select paths.</div>
            </div>
            
            <!-- Container for the filetree -->
            <div id="{$inputId}-tree-container" class="filetree-container" style="display: none; background-color: {$bgcolor};"></div>
        </div>
HTML;
        
        return $html;
    }
}