// Function to kill stuck operations

(function(P) {
    // Add the kill stuck operation function to the Par2Protect object
    P.killStuckOperation = function(operationId) {
        if (P.config.isLoading) {
            P.logger.debug('Kill operation skipped - already loading');
            return;
        }
        
        P.logger.debug('Killing stuck operation:', { operationId });
        P.setLoading(true);
        
        $.ajax({
            url: '/plugins/par2protect/api/v1/index.php?endpoint=queue/kill',
            method: 'POST',
            data: {
                operation_id: operationId
            },
            dataType: 'json',
            timeout: 10000,
            success: function(response) {
                P.logger.debug('Kill operation response:', { response });
                
                if (response.success) {
                    swal({
                        title: 'Success',
                        text: response.message || 'Stuck operation killed successfully',
                        type: 'success'
                    });
                    
                    // Update status to reflect changes
                    if (P.dashboard) {
                        P.dashboard.updateStatus(true);
                    }
                } else {
                    swal({
                        title: 'Error',
                        text: response.error || 'Failed to kill stuck operation',
                        type: 'error'
                    });
                }
            },
            error: function(xhr, status, error) {
                P.logger.error('Kill operation request failed:', {
                    status: status,
                    error: error,
                    response: xhr.responseText
                });
                
                swal({
                    title: 'Error',
                    text: 'Failed to kill stuck operation: ' + error,
                    type: 'error'
                });
            },
            complete: function() {
                P.setLoading(false);
            }
        });
    };
})(window.Par2Protect);