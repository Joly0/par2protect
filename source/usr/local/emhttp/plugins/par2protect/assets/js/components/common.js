// Common Utility Functions and Base Configuration

// Add notice function if not available
if (typeof addNotice === 'undefined') {
    window.addNotice = function(message) {
        var notice = $('<div class="notice-container">')
            .html(message);
        $('body').append(notice);
        setTimeout(function() {
            notice.remove();
        }, 3000);
    };
}

// Add swal function if not available
if (typeof swal === 'undefined') {
    window.swal = function(title, message, type) {
        if (typeof title === 'object') {
            message = title.text;
            type = title.type;
            title = title.title;
        }
        alert(title + '\n\n' + message);
    };
}

window.Par2Protect = window.Par2Protect || {
    // Configuration and state
    config: {
        updateInterval: 5000,
        statusCheckTimer: null,
        isLoading: false,
        isInitialized: false,
        fileCategories: {
            video: {
                extensions: ['mp4', 'mkv', 'avi', 'mov'],
                description: 'Video Files'
            },
            audio: {
                extensions: ['mp3', 'flac', 'wav', 'm4a'],
                description: 'Audio Files'
            },
            images: {
                extensions: ['jpg', 'png', 'raw', 'tiff'],
                description: 'Image Files'
            },
            documents: {
                extensions: ['pdf', 'doc', 'docx', 'txt'],
                description: 'Documents'
            }
        }
    },

    // Set loading state
    setLoading: function(loading) {
        this.config.isLoading = loading;
        if (loading) {
            $('.par2-status-card:not(.quick-actions)').addClass('loading');
            $('button:not(.add-folder)').prop('disabled', loading);
        } else {
            $('.par2-status-card').removeClass('loading');
            $('button').prop('disabled', false);
        }
    }
};