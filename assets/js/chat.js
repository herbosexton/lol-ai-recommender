/**
 * Chat UI JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        const chatContainer = $('#lol-chat-container');
        if (!chatContainer.length) {
            return;
        }
        
        const messagesContainer = $('#lol-chat-messages');
        const input = $('#lol-chat-input');
        const sendButton = $('#lol-chat-send');
        
        // Generate or retrieve session ID
        let sessionId = localStorage.getItem('lol_chat_session_id');
        if (!sessionId) {
            // Generate UUID-like session ID
            sessionId = 'lol_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            localStorage.setItem('lol_chat_session_id', sessionId);
        }
        
        // Initialize with welcome message (cannabis-friendly, conversational)
        addMessage('assistant', 'Hi! I\'m your Legacy on Lark guide. Whether you\'re looking for flower, edibles, vapes, or something for relaxation, sleep, or focus—ask me anything and I\'ll point you to the right products.');
        
        // Add reset button
        const resetButton = $('<button>')
            .addClass('lol-chat-reset')
            .text('New Chat')
            .on('click', resetChat);
        
        $('.lol-chat-header').append(resetButton);
        
        // Send on button click
        sendButton.on('click', sendMessage);
        
        // Send on Enter key
        input.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        function resetChat() {
            if (!confirm('Start a new conversation? This will clear the current chat.')) {
                return;
            }
            
            // Clear UI
            messagesContainer.empty();
            addMessage('assistant', 'Hi! I\'m your Legacy on Lark guide. Whether you\'re looking for flower, edibles, vapes, or something for relaxation, sleep, or focus—ask me anything and I\'ll point you to the right products.');
            
            // Reset session on server
            $.ajax({
                url: lolChat.apiUrl.replace('/chat', '/chat/reset'),
                method: 'POST',
                data: {
                    session_id: sessionId
                },
                headers: {
                    'X-WP-Nonce': lolChat.nonce
                },
                success: function() {
                    // Generate new session ID
                    sessionId = 'lol_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    localStorage.setItem('lol_chat_session_id', sessionId);
                },
                error: function() {
                    // Still generate new session ID even if reset fails
                    sessionId = 'lol_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    localStorage.setItem('lol_chat_session_id', sessionId);
                }
            });
            
            input.focus();
        }
        
        function sendMessage() {
            const message = input.val().trim();
            if (!message) {
                return;
            }
            
            // Add user message to UI
            addMessage('user', message);
            input.val('');
            
            // Show loading
            const loadingId = addMessage('assistant', '...', true);
            
            // Disable input
            input.prop('disabled', true);
            sendButton.prop('disabled', true).text(lolChat.strings.sending);
            
            // Send to API (always include session_id)
            sendChatRequest(message, loadingId, 0);
        }
        
        function sendChatRequest(message, loadingId, retryCount) {
            $.ajax({
                url: lolChat.apiUrl,
                method: 'POST',
                data: {
                    message: message,
                    session_id: sessionId || ''
                },
                headers: {
                    'X-WP-Nonce': lolChat.nonce
                },
                success: function(response) {
                    // Remove loading message
                    $('#' + loadingId).remove();
                    
                    // Handle structured response
                    if (!response.ok) {
                        handleErrorResponse(response, loadingId, message, retryCount);
                        return;
                    }
                    
                    // Update session ID
                    if (response.session_id) {
                        sessionId = response.session_id;
                        localStorage.setItem('lol_chat_session_id', sessionId);
                    }
                    
                    // Add assistant response
                    if (response.assistant_message) {
                        addMessage('assistant', response.assistant_message);
                    } else if (response.response) {
                        // Fallback for old format
                        addMessage('assistant', response.response);
                    }
                    
                    // Add product recommendations
                    const products = response.recommendations || response.products || [];
                    if (products.length > 0) {
                        addProducts(products);
                    }
                    
                    // Add clarifying questions as quick-reply buttons
                    const questions = response.clarifying_questions || response.questions || [];
                    if (questions.length > 0) {
                        addClarifyingQuestions(questions);
                    }
                    
                    // Re-enable input
                    input.prop('disabled', false);
                    sendButton.prop('disabled', false).text('Send');
                    input.focus();
                    
                    // Scroll to bottom
                    scrollToBottom();
                },
                error: function(xhr) {
                    // Remove loading message
                    $('#' + loadingId).remove();
                    
                    // Try to parse error response
                    let errorResponse = null;
                    if (xhr.responseJSON) {
                        errorResponse = xhr.responseJSON;
                    }
                    
                    if (errorResponse && !errorResponse.ok) {
                        handleErrorResponse(errorResponse, loadingId, message, retryCount);
                    } else {
                        // Generic error
                        addMessage('assistant', 'I\'m having a technical issue right now. Could you try rephrasing your question, or feel free to browse our menu directly?', false, 'error');
                        input.prop('disabled', false);
                        sendButton.prop('disabled', false).text('Send');
                        input.focus();
                        scrollToBottom();
                    }
                }
            });
        }
        
        function handleErrorResponse(response, loadingId, originalMessage, retryCount) {
            const errorType = response.error_type || 'unknown';
            const retryAfter = response.retry_after || 5;
            
            if (errorType === 'rate_limited' && retryCount === 0) {
                // Show countdown and auto-retry once
                let countdown = retryAfter;
                const countdownMsg = addMessage('assistant', 'Rate limit reached. Retrying in ' + countdown + ' seconds...', false, 'warning');
                
                const countdownInterval = setInterval(function() {
                    countdown--;
                    if (countdown > 0) {
                        $('#' + countdownMsg).html('Rate limit reached. Retrying in ' + countdown + ' seconds...');
                    } else {
                        clearInterval(countdownInterval);
                        $('#' + countdownMsg).remove();
                        // Retry once
                        const newLoadingId = addMessage('assistant', '...', true);
                        input.prop('disabled', true);
                        sendButton.prop('disabled', true).text('Retrying...');
                        sendChatRequest(originalMessage, newLoadingId, 1);
                    }
                }, 1000);
            } else if (errorType === 'temporary' && retryCount === 0) {
                // Auto-retry once for temporary errors
                setTimeout(function() {
                    const newLoadingId = addMessage('assistant', 'Retrying...', true);
                    input.prop('disabled', true);
                    sendButton.prop('disabled', true).text('Retrying...');
                    sendChatRequest(originalMessage, newLoadingId, 1);
                }, retryAfter * 1000);
            } else {
                // Show error message
                const errorMsg = response.assistant_message || 'I\'m having trouble right now. Please try again in a moment, or feel free to browse our menu directly.';
                addMessage('assistant', errorMsg, false, 'error');
                input.prop('disabled', false);
                sendButton.prop('disabled', false).text('Send');
                input.focus();
                scrollToBottom();
            }
        }
        
        function addMessage(role, content, isLoading, className) {
            const messageId = 'lol-msg-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
            const messageClass = 'lol-message lol-message-' + role + (className ? ' ' + className : '');
            
            const messageHtml = $('<div>')
                .attr('id', messageId)
                .addClass(messageClass)
                .html('<div class="lol-message-content">' + (isLoading ? '<span class="lol-loading">' + content + '</span>' : formatMessage(content)) + '</div>');
            
            messagesContainer.append(messageHtml);
            scrollToBottom();
            
            return messageId;
        }
        
        function formatMessage(content) {
            // Simple formatting: line breaks, basic markdown
            content = content.replace(/\n/g, '<br>');
            // Escape HTML but allow <br>
            const div = $('<div>').text(content);
            return div.html().replace(/\n/g, '<br>');
        }
        
        function addProducts(products) {
            if (!products || products.length === 0) {
                return;
            }
            
            const productsHtml = $('<div>').addClass('lol-products');
            
            products.forEach(function(product) {
                const productUrl = product.remote_url || product.url || '#';
                const productImage = product.image ? '<img src="' + escapeHtml(product.image) + '" alt="' + escapeHtml(product.name) + '" class="lol-product-image">' : '';
                
                const productHtml = $('<div>')
                    .addClass('lol-product')
                    .html(
                        productImage +
                        '<div class="lol-product-header">' +
                            '<h4 class="lol-product-name">' + escapeHtml(product.name) + '</h4>' +
                            (product.price ? '<span class="lol-product-price">$' + escapeHtml(product.price) + '</span>' : '') +
                        '</div>' +
                        (product.category ? '<div class="lol-product-category">' + escapeHtml(product.category) + '</div>' : '') +
                        (product.short_reason ? '<div class="lol-product-reason">' + escapeHtml(product.short_reason) + '</div>' : '') +
                        (product.description ? '<div class="lol-product-description">' + escapeHtml(product.description) + '</div>' : '') +
                        '<a href="' + escapeHtml(productUrl) + '" target="_blank" rel="noopener noreferrer" class="lol-product-link button">View/Buy on Dutchie</a>'
                    );
                
                productsHtml.append(productHtml);
            });
            
            messagesContainer.append(productsHtml);
            scrollToBottom();
        }
        
        function addQuestions(questions) {
            if (!questions || questions.length === 0) {
                return;
            }
            
            const questionsHtml = $('<div>').addClass('lol-questions');
            
            questions.forEach(function(question) {
                const questionButton = $('<button>')
                    .addClass('lol-question-button')
                    .text(question)
                    .on('click', function() {
                        input.val(question);
                        sendMessage();
                    });
                
                questionsHtml.append(questionButton);
            });
            
            messagesContainer.append(questionsHtml);
            scrollToBottom();
        }
        
        function scrollToBottom() {
            messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
        }
        
        function escapeHtml(text) {
            const div = $('<div>').text(text);
            return div.html();
        }
        
        // Focus input on load
        input.focus();
    });
})(jQuery);
