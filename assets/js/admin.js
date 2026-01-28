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
        
        const testButton = $('#lol-test-crawler');
        const testStatus = $('#lol-test-status');
        const testResults = $('#lol-test-results');
        const testResultsContent = $('#lol-test-results-content');
        
        // Test button handler
        if (testButton.length) {
            testButton.on('click', function() {
                const button = $(this);
                const originalText = button.text();
                
                button.prop('disabled', true).text('Testing...');
                testStatus.text('').removeClass('success error');
                testResults.hide();
                
                $.ajax({
                    url: lolAdmin.apiUrl.replace('/sync', '/test'),
                    method: 'GET',
                    headers: {
                        'X-WP-Nonce': lolAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            testStatus.text('Test completed!').addClass('success');
                            testResultsContent.text(JSON.stringify(response, null, 2));
                            testResults.show();
                        } else {
                            testStatus.text('Test failed. See details below.').addClass('error');
                            testResultsContent.text(JSON.stringify(response, null, 2));
                            testResults.show();
                        }
                    },
                    error: function(xhr) {
                        let errorMessage = 'Test failed.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        }
                        testStatus.text(errorMessage).addClass('error');
                        testResultsContent.text('Error: ' + (xhr.responseText || errorMessage));
                        testResults.show();
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
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
                    const detailsDiv = $('#lol-sync-details');
                    const detailsContent = $('#lol-sync-details-content');
                    
                    if (response.success) {
                        syncStatus.text('Sync completed! Synced: ' + (response.synced || 0) + ' products, Skipped: ' + (response.skipped || 0)).addClass('success');
                        
                        let details = 'Success!\n';
                        details += 'Synced: ' + (response.synced || 0) + ' products\n';
                        details += 'Skipped: ' + (response.skipped || 0) + ' products\n';
                        
                        if (response.errors && response.errors.length > 0) {
                            details += '\nErrors encountered:\n';
                            details += response.errors.join('\n');
                        }
                        
                        detailsContent.text(details);
                        detailsDiv.show();
                        
                        // Reload page after 3 seconds to show updated status
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        syncStatus.text('Sync failed. See details below.').addClass('error');
                        
                        let details = 'Sync Failed!\n\n';
                        if (response.errors && response.errors.length > 0) {
                            details += 'Errors:\n';
                            details += response.errors.join('\n');
                        } else {
                            details += 'Unknown error occurred.';
                        }
                        
                        detailsContent.text(details);
                        detailsDiv.show();
                    }
                },
                error: function(xhr) {
                    const detailsDiv = $('#lol-sync-details');
                    const detailsContent = $('#lol-sync-details-content');
                    
                    let errorMessage = 'Sync failed.';
                    let details = 'HTTP Error\n\n';
                    
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                        details += 'Message: ' + xhr.responseJSON.message + '\n';
                    }
                    
                    if (xhr.status) {
                        details += 'Status Code: ' + xhr.status + '\n';
                    }
                    
                    if (xhr.responseText) {
                        details += 'Response: ' + xhr.responseText.substring(0, 500);
                    }
                    
                    syncStatus.text(errorMessage).addClass('error');
                    detailsContent.text(details);
                    detailsDiv.show();
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });
    });
})(jQuery);
