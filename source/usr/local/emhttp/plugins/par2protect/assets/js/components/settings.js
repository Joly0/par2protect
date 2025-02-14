// Settings Page Functionality

(function(P) {
    // Settings methods
    const settings = {
        // Initialize settings
        init: function() {
            // Currently no special initialization needed
            // Form submission is handled by Unraid's built-in form handling
            console.log('Settings initialized');
        }
    };

    // Add settings methods to Par2Protect
    P.settings = settings;

    // Initialize when document is ready
    $(document).ready(function() {
        // Check if we're on the settings page
        if (document.querySelector('form[name="#file"][value="par2protect/par2protect.cfg"]')) {
            P.settings.init();
        }
    });

})(window.Par2Protect);