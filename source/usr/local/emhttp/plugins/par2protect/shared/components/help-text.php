<?php
/**
 * PAR2Protect Help Text Component
 * 
 * This file provides a simple function to add help text to any element in the PAR2Protect plugin.
 * Include this file in any PHP page where you want to add help text.
 * 
 * Usage:
 * <?php include '/usr/local/emhttp/plugins/par2protect/shared/components/help-text.php'; ?>
 */

/**
 * Generate help text HTML
 * 
 * @param string $label The label text
 * @param string $helpText The help text content (can include HTML)
 * @param string $inputHtml The HTML for the input element (optional)
 * @param string $helpId Custom ID for the help text (optional)
 * @return string The generated HTML
 */
function helpText($label, $helpText, $inputHtml = '', $helpId = '') {
    // Generate a random ID if none provided
    if (empty($helpId)) {
        $helpId = 'help_' . md5($label . rand());
    }
    
    $html = <<<HTML
<dl>
  <dt style="cursor: help" class="help-trigger" data-help-id="$helpId">$label</dt>
  <dd>
    $inputHtml
  </dd>
</dl>

<blockquote id="$helpId" class="inline_help" style="display: none;">
  $helpText
</blockquote>
HTML;
    
    return $html;
}

/**
 * Add the help text JavaScript to the page
 * This should be called once at the end of the page
 */
function includeHelpTextJs() {
    echo '<script src="/plugins/par2protect/shared/js/help-text.js"></script>';
}

// Example usage:
/*
// At the top of your PHP file
include '/usr/local/emhttp/plugins/par2protect/shared/components/help-text.php';

// In your HTML
echo helpText(
    'Default Redundancy Level (%):', 
    'The default percentage of redundancy to use when protecting files.<br>
     <strong>Recommended:</strong> 10% for most use cases.',
    '<select name="default_redundancy">
       <option value="10">10</option>
       <option value="20">20</option>
     </select>'
);

// At the end of your PHP file
includeHelpTextJs();
*/