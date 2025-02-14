<?php
require_once "/usr/local/emhttp/webGui/include/Helpers.php";

/**
 * Multi-select Filetree Picker Component for Unraid plugins
 * Location: /usr/local/emhttp/plugins/par2protect/include/FileTreePicker.php
 */
class FileTreePicker {
    /**
     * Renders a file tree picker with multi-select capabilities
     *
     * @param string $textareaId The ID for the hidden input element
     * @param string $textareaName The name for the hidden input element
     * @param string $currentValue Current selected paths (newline separated)
     * @param bool $required Whether the field is required
     * @return string The HTML for the file tree picker
     */
    public static function render($textareaId, $textareaName, $currentValue = '', $required = false) {
        $requiredAttr = $required ? ' required="required"' : '';
        
        // Convert current value to array for easier handling
        $currentPaths = array_filter(explode("\n", $currentValue));
        
        // Get Unraid theme background color for dynamic styling
        global $display;
        $bgcolor = strstr('white,azure', $display['theme']) ? '#f2f2f2' : '#1c1c1c';
        
        $html = <<<HTML
        <script type="text/javascript" src="/webGui/javascript/jquery.filetree.js"></script>
        <link type="text/css" rel="stylesheet" href="/webGui/styles/jquery.filetree.css">
        <link type="text/css" rel="stylesheet" href="/plugins/par2protect/assets/css/components/filetree-picker.css">
        <script type="text/javascript" src="/plugins/par2protect/assets/js/components/filetree-picker.js"></script>

        <div class="filetree-wrapper" id="{$textareaId}-wrapper">
            <div class="filetree-container">
                <input type="hidden"
                    id="{$textareaId}"
                    name="{$textareaName}"
                    value="{$currentValue}"
                    {$requiredAttr}
                >
                <div class="selected-paths-container" style="background:{$bgcolor}">
                    <!-- Path items will be added here dynamically -->
                </div>
                <div class="ft" style="display:none;background:{$bgcolor}" onclick="event.stopPropagation();">
                    <div id="fileTree-{$textareaId}" class="fileTreeDiv"></div>
                    <button onclick="addSelectionToList(this); return false;">Add to list</button>
                </div>
            </div>
        </div>

        <script>
        var currentTreeId = '#{$textareaId}';
        </script>
HTML;
        
        return $html;
    }
}
