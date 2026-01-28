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
                    
                    // Update session ID
                    if (response.session_id) {
                        sessionId = response.session_id;
                        localStorage.setItem('lol_chat_session_id', sessionId);
                    }
                    
                    // Add assistant response
                    if (response.response) {
                        addMessage('assistant', response.response);
                    }
                    
                    // Add product recommendations
                    if (response.products && response.products.length > 0) {
                        addProducts(response.products);
                    }
                    
                    // Add follow-up questions if provided
                    if (response.questions && response.questions.length > 0) {
                        addQuestions(response.questions);
                    }
                },
                error: function(xhr) {
                    // Remove loading message
                    $('#' + loadingId).remove();
                    
                    let errorMessage = lolChat.strings.error;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }
                    
                    addMessage('assistant', errorMessage, false, 'error');
                },
                complete: function() {
                    // Re-enable input
                    input.prop('disabled', false);
                    sendButton.prop('disabled', false).text('Send');
                    input.focus();
                    
                    // Scroll to bottom
                    scrollToBottom();
                }
            });
        }
        
        function addMessage(role, content, isLoading, className) {
            const messageId = 'lol-msg-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
            const messageClass = 'lol-message lol-message-' + role + (className ? ' ' + className : '');
            
            const messageHtml = $('<div>')
                .attr('id', messageId)
                .addClass(messageClass)
                .html(isLoading ? '<span class="lol-loading">' + content + '</span>' : formatMessage(content));
            
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
                const productHtml = $('<div>')
                    .addClass('lol-product')
                    .html(
                        '<div class="lol-product-header">' +
                            '<h4 class="lol-product-name">' + escapeHtml(product.name) + '</h4>' +
                            (product.price ? '<span class="lol-product-price">$' + escapeHtml(product.price) + '</span>' : '') +
                        '</div>' +
                        (product.category ? '<div class="lol-product-category">' + escapeHtml(product.category) + '</div>' : '') +
                        (product.short_reason ? '<div class="lol-product-reason">' + escapeHtml(product.short_reason) + '</div>' : '') +
                        '<a href="' + escapeHtml(product.remote_url) + '" target="_blank" rel="noopener" class="lol-product-link button">View/Buy on Dutchie</a>'
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
