/**
 * Admin JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        const syncButton = $('#lol-manual-sync');
        const syncStatus = $('#lol-sync-status');
        
        if (!syncButton.length) {
            return;
        }
        
        syncButton.on('click', function() {
            const button = $(this);
            const originalText = button.text();
            
            button.prop('disabled', true).text('Syncing...');
            syncStatus.text('').removeClass('success error');
            
            $.ajax({
                url: lolAdmin.apiUrl,
                method: 'POST',
                headers: {
                    'X-WP-Nonce': lolAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        syncStatus.text('Sync completed! Synced: ' + (response.synced || 0) + ' products').addClass('success');
                        // Reload page after 2 seconds to show updated status
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        syncStatus.text('Sync failed. Check errors below.').addClass('error');
                    }
                },
                error: function(xhr) {
                    let errorMessage = 'Sync failed.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }
                    syncStatus.text(errorMessage).addClass('error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });
    });
})(jQuery);
