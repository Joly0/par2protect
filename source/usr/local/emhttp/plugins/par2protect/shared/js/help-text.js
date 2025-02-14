/**
 * PAR2Protect Help Text Functionality
 * 
 * This file provides the JavaScript functionality needed to toggle help text
 * visibility in the PAR2Protect plugin.
 */

/**
 * Initialize help text functionality
 * This function should be called when the document is ready
 */
function initHelpText() {
    // Add click handlers to all elements with the help-trigger class
    $('.help-trigger').on('click', function() {
        // Find the associated help text by ID or next blockquote
        var helpId = $(this).data('help-id');
        var helpText;
        
        if (helpId) {
            // If a specific help ID is provided, use that
            helpText = $('#' + helpId);
        } else {
            // Otherwise, look for the next blockquote with inline_help class
            helpText = $(this).closest('dl, div').find('blockquote.inline_help').first();
        }
        
        // Toggle the visibility of the help text
        if (helpText.length) {
            helpText.toggle('fast');
        }
        
        return false; // Prevent default action
    });
}

/**
 * Document ready handler
 */
$(function() {
    // Initialize help text functionality when the document is ready
    initHelpText();
});