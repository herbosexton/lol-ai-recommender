<?php
/**
 * REST API Endpoints
 */

if (!defined('ABSPATH')) {
    exit;
}

class LOL_REST {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register REST routes
     */
    public function register_routes() {
        // Chat endpoint
        register_rest_route('lol/v1', '/chat', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_chat'),
            'permission_callback' => '__return_true',
            'args' => array(
                'message' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'session_id' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
        
        // Manual sync endpoint (admin only)
        register_rest_route('lol/v1', '/sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_sync'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
        
        // Test endpoint (admin only)
        register_rest_route('lol/v1', '/test', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_test'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args' => array(
                'url' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ),
            ),
        ));
        
        // Reset chat endpoint
        register_rest_route('lol/v1', '/chat/reset', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_reset'),
            'permission_callback' => '__return_true',
            'args' => array(
                'session_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }
    
    /**
     * Handle chat request
     */
    public function handle_chat($request) {
        // Rate limiting
        $ip = $this->get_client_ip();
        $rate_limit = get_option('lol_chat_rate_limit', 10);
        
        if (!$this->check_rate_limit($ip, $rate_limit)) {
            return new WP_Error('rate_limit_exceeded', __('Rate limit exceeded. Please wait a moment.', 'lol-ai-recommender'), array('status' => 429));
        }
        
        $message = trim($request->get_param('message'));
        $session_id = $request->get_param('session_id');
        
        if (empty($session_id)) {
            $session_id = $this->generate_session_id();
        }
        
        // Get conversation history
        $conversation = $this->get_conversation_history($session_id);
        
        // Check if this is a topic change or reset trigger
        $should_reset = $this->should_reset_conversation($message, $conversation);
        if ($should_reset) {
            $conversation = array(); // Start fresh
            $this->log_debug('Conversation reset triggered', array('session_id' => $session_id, 'message' => $message));
        }
        
        // Trim conversation to last 10 messages (5 turns) to prevent stale context
        $conversation = array_slice($conversation, -10);
        
        // Add NEW user message (this is the current turn)
        $conversation[] = array('role' => 'user', 'content' => $message);
        
        // Check if we need to ask questions (only for very early exchanges)
        $openai = LOL_OpenAI::get_instance();
        $recommend = LOL_Recommend::get_instance();
        
        $should_ask_questions = count($conversation) <= 2; // Only first user message
        
        if ($should_ask_questions) {
            // Generate consumer-perspective suggested prompts (what the customer could ask)
            $categories = $recommend->get_available_categories();
            $effects = $recommend->get_available_effects();
            $suggested_prompts = $openai->generate_questions($conversation, $categories, $effects);
            
            // Friendly, cannabis-knowledgeable reply to the user's CURRENT message only
            $system_prompt = "You are a friendly, knowledgeable cannabis dispensary assistant at Legacy on Lark. You can discuss cannabis basics, product types (flower, vapes, edibles, concentrates), effects (relaxation, sleep, focus, creativity), and terpenes in simple terms. Keep it conversational and helpful. Respond ONLY to the latest user message. Do NOT repeat previous responses. Do NOT ask the customer questions—respond to what they said. If they said hello or something brief, give a warm welcome and invite them to ask what they're looking for. Keep response under 80 words.";
            
            // Use ONLY the latest user message for early exchanges
            $reply_messages = array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user', 'content' => $message), // Only current message
            );
            
            $ai_reply = $openai->chat_completion($reply_messages);
            $response_text = is_wp_error($ai_reply) ? __('Hi! I\'m here to help you find the right products. What are you looking for today—relaxation, sleep, focus, or something else?', 'lol-ai-recommender') : $ai_reply;
            
            // Add assistant response to conversation
            $conversation[] = array('role' => 'assistant', 'content' => $response_text);
            
            // Save conversation state
            $this->save_conversation_state($session_id, $conversation, $response_text);
            
            $this->log_debug('Early exchange response', array(
                'session_id' => $session_id,
                'message_count' => count($conversation),
                'user_message' => $message,
                'reset_triggered' => $should_reset,
            ));
            
            return rest_ensure_response(array(
                'response' => $response_text,
                'questions' => $suggested_prompts,
                'products' => array(),
                'session_id' => $session_id,
            ));
        }
        
        // Get available categories and brands for context
        $available_categories = $recommend->get_available_categories();
        $available_brands = $recommend->get_available_brands();
        
        // Use recent conversation context (last 2-3 turns) for filter extraction if topic changed
        $conversation_for_filters = $conversation;
        if ($should_reset || $this->is_topic_change($message, $conversation)) {
            // Use only last 2 messages (1 turn) if topic changed
            $conversation_for_filters = array_slice($conversation, -2);
            $this->log_debug('Topic change detected, using limited context', array('session_id' => $session_id));
        }
        
        // Extract filters from conversation (focusing on latest intent)
        $filters = $openai->extract_filters($conversation_for_filters, $available_categories, $available_brands);
        
        if (is_wp_error($filters)) {
            // Fallback: use current message only
            $filters = array(
                'intent_summary' => $message,
                'filters' => array(),
                'must_have' => array(),
                'avoid' => array(),
                'top_n' => 5,
            );
        }
        
        // Get recommendations
        $top_n = isset($filters['top_n']) ? intval($filters['top_n']) : 5;
        $products = $recommend->get_recommendations($filters['filters'], $top_n);
        
        // Build product context with categories and descriptions
        $product_context = array();
        foreach ($products as $product) {
            $product_info = $product['name'];
            if (!empty($product['category'])) {
                $product_info .= ' (' . $product['category'] . ')';
            }
            if (!empty($product['price'])) {
                $product_info .= ' - $' . $product['price'];
            }
            if (!empty($product['short_reason'])) {
                $product_info .= ' - ' . $product['short_reason'];
            }
            $product_context[] = $product_info;
        }
        
        $product_list = !empty($product_context) ? "\n\nRecommended products:\n" . implode("\n", array_slice($product_context, 0, 5)) : '';
        
        // Generate AI response - use recent conversation context
        $category_info = !empty($filters['filters']['category']) ? "\n\nCustomer is interested in: " . $filters['filters']['category'] : '';
        $effects_info = !empty($filters['filters']['effects']) ? "\n\nDesired effects: " . implode(', ', $filters['filters']['effects']) : '';
        
        $system_prompt = "You are a friendly, knowledgeable cannabis dispensary assistant at Legacy on Lark. You understand cannabis basics: flower, vapes, edibles, concentrates, pre-rolls, terpenes, and effects (relaxation, sleep, focus, creativity, etc.). IMPORTANT: Respond ONLY to the LATEST user message. Do NOT repeat previous responses. Do NOT continue previous topics unless the user explicitly continues them. If the user asks a new question, answer that new question. Recommend products naturally and conversationally. Mention 2-3 specific products by name, their category, and why they match. You can briefly explain cannabis concepts if relevant (e.g. indica vs sativa, terpenes) in simple terms. Be warm and helpful. Keep response under 200 words.";
        
        // Build messages array with system prompt + recent conversation (last 6 messages = 3 turns)
        $recent_conversation = array_slice($conversation, -6);
        $ai_messages = array_merge(
            array(array('role' => 'system', 'content' => $system_prompt)),
            $recent_conversation
        );
        
        // Get last assistant response hash to detect repeats
        $last_response_hash = $this->get_last_response_hash($session_id);
        
        $ai_response = $openai->chat_completion($ai_messages);
        
        if (is_wp_error($ai_response)) {
            // Fallback response
            $ai_response = __('Based on your preferences, here are some great options:', 'lol-ai-recommender');
        }
        
        // Check for repeat responses
        $response_hash = md5(strtolower(trim($ai_response)));
        if ($last_response_hash && $response_hash === $last_response_hash) {
            $this->log_debug('Repeat response detected, retrying', array('session_id' => $session_id));
            // Retry with stronger instruction
            $retry_system_prompt = $system_prompt . "\n\nCRITICAL: The user just sent a NEW message. Do NOT repeat your previous response. Answer ONLY the latest user message with fresh content.";
            $retry_messages = array_merge(
                array(array('role' => 'system', 'content' => $retry_system_prompt)),
                array_slice($conversation, -2) // Only last turn
            );
            $retry_response = $openai->chat_completion($retry_messages);
            if (!is_wp_error($retry_response) && md5(strtolower(trim($retry_response))) !== $last_response_hash) {
                $ai_response = $retry_response;
            } else {
                // Still repeating, use a simple response
                $ai_response = __('I understand you\'re asking about something new. ' . $message . ' - Let me help you with that. ' . (!empty($products) ? 'Here are some options:' : 'What specifically are you looking for?'), 'lol-ai-recommender');
            }
        }
        
        // Add assistant response to conversation
        $conversation[] = array('role' => 'assistant', 'content' => $ai_response);
        
        // Save conversation state
        $this->save_conversation_state($session_id, $conversation, $ai_response);
        
        $this->log_debug('Chat response generated', array(
            'session_id' => $session_id,
            'message_count' => count($conversation),
            'user_message' => $message,
            'products_found' => count($products),
            'reset_triggered' => $should_reset,
        ));
        
        return rest_ensure_response(array(
            'response' => $ai_response,
            'products' => $products,
            'session_id' => $session_id,
        ));
    }
    
    /**
     * Handle reset request
     */
    public function handle_reset($request) {
        // Verify nonce
        $nonce = $request->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('invalid_nonce', __('Security check failed', 'lol-ai-recommender'), array('status' => 403));
        }
        
        $session_id = sanitize_text_field($request->get_param('session_id'));
        
        if (empty($session_id)) {
            return new WP_Error('missing_session', __('Session ID required', 'lol-ai-recommender'), array('status' => 400));
        }
        
        // Delete all session-related transients
        $this->reset_session($session_id);
        
        $this->log_debug('Session reset', array('session_id' => $session_id));
        
        return rest_ensure_response(array(
            'ok' => true,
            'session_id' => $session_id,
        ));
    }
    
    /**
     * Handle manual sync request
     */
    public function handle_sync($request) {
        $sync = LOL_Sync::get_instance();
        $result = $sync->sync_products();
        
        return rest_ensure_response($result);
    }
    
    /**
     * Handle test request - test crawler and parser on a single URL
     */
    public function handle_test($request) {
        $test_url = $request->get_param('url');
        $menu_base = get_option('lol_dutchie_menu_base_url', '');
        
        $results = array(
            'success' => false,
            'tests' => array(),
        );
        
        // Test 1: Check configuration
        $results['tests']['configuration'] = array(
            'menu_base_url' => $menu_base,
            'sitemap_url' => get_option('lol_dutchie_sitemap_url', ''),
            'openai_configured' => LOL_OpenAI::get_instance()->is_configured(),
        );
        
        // Test 2: Test URL fetching
        if (empty($test_url) && !empty($menu_base)) {
            $test_url = $menu_base;
        }
        
        if (empty($test_url)) {
            $results['error'] = 'No URL provided. Add ?url=https://example.com/product to test a specific URL, or configure Menu Base URL.';
            return rest_ensure_response($results);
        }
        
        $crawler = LOL_Crawler::get_instance();
        
        // Test 2: Fetch a page
        $results['tests']['fetch_page'] = array(
            'url' => $test_url,
            'status' => 'testing',
        );
        
        $page_data = $crawler->fetch_product_page($test_url);
        
        if (is_wp_error($page_data)) {
            $results['tests']['fetch_page']['status'] = 'error';
            $results['tests']['fetch_page']['error'] = $page_data->get_error_message();
            return rest_ensure_response($results);
        }
        
        $results['tests']['fetch_page']['status'] = 'success';
        $results['tests']['fetch_page']['body_length'] = strlen($page_data['body']);
        $results['tests']['fetch_page']['has_etag'] = !empty($page_data['etag']);
        
        // Test 3: Parse product data
        $parser = LOL_Parser::get_instance();
        $product_data = $parser->parse_product($page_data['body'], $test_url);
        
        $results['tests']['parse_product'] = array(
            'status' => 'success',
            'data' => array(
                'name' => $product_data['name'],
                'category' => $product_data['category'],
                'brand' => $product_data['brand'],
                'description_length' => strlen($product_data['description']),
                'price' => $product_data['price'],
                'thc' => $product_data['thc'],
                'cbd' => $product_data['cbd'],
                'has_image' => !empty($product_data['image_url']),
                'in_stock' => $product_data['in_stock'],
                'effects' => $product_data['effects'],
            ),
        );
        
        // Test 4: Test product URL discovery (if menu base provided)
        if (!empty($menu_base)) {
            $results['tests']['discover_urls'] = array(
                'status' => 'testing',
                'menu_base' => $menu_base,
            );
            
            $discovered_urls = $crawler->scrape_product_urls($menu_base, 3); // Limit to 3 pages for testing
            
            if (is_wp_error($discovered_urls)) {
                $results['tests']['discover_urls']['status'] = 'error';
                $results['tests']['discover_urls']['error'] = $discovered_urls->get_error_message();
            } else {
                $results['tests']['discover_urls']['status'] = 'success';
                $results['tests']['discover_urls']['urls_found'] = count($discovered_urls);
                $results['tests']['discover_urls']['sample_urls'] = array_slice($discovered_urls, 0, 5);
            }
        }
        
        $results['success'] = true;
        
        return rest_ensure_response($results);
    }
    
    /**
     * Check admin permission
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }
    
    /**
     * Get conversation history from transient
     */
    private function get_conversation_history($session_id) {
        $cache_key = 'lol_conversation_' . md5($session_id);
        $history = get_transient($cache_key);
        return $history ? $history : array();
    }
    
    /**
     * Save conversation history and state
     */
    private function save_conversation_history($session_id, $conversation) {
        $this->save_conversation_state($session_id, $conversation, '');
    }
    
    /**
     * Save conversation state with response hash
     */
    private function save_conversation_state($session_id, $conversation, $last_response = '') {
        $cache_key = 'lol_conversation_' . md5($session_id);
        // Keep last 10 messages (5 turns) to prevent stale context
        $conversation = array_slice($conversation, -10);
        set_transient($cache_key, $conversation, HOUR_IN_SECONDS * 2);
        
        // Store last response hash for repeat detection
        if (!empty($last_response)) {
            $hash_key = 'lol_response_hash_' . md5($session_id);
            set_transient($hash_key, md5(strtolower(trim($last_response))), HOUR_IN_SECONDS * 2);
        }
    }
    
    /**
     * Get last response hash
     */
    private function get_last_response_hash($session_id) {
        $hash_key = 'lol_response_hash_' . md5($session_id);
        return get_transient($hash_key);
    }
    
    /**
     * Reset session (delete all related transients)
     */
    private function reset_session($session_id) {
        $session_hash = md5($session_id);
        delete_transient('lol_conversation_' . $session_hash);
        delete_transient('lol_response_hash_' . $session_hash);
    }
    
    /**
     * Check if conversation should be reset
     */
    private function should_reset_conversation($message, $conversation) {
        $message_lower = strtolower(trim($message));
        
        // Greeting words that suggest a fresh start
        $greetings = array('hi', 'hello', 'hey', 'hi there', 'hello there', 'hey there', 'new chat', 'start over', 'reset');
        
        foreach ($greetings as $greeting) {
            if ($message_lower === $greeting || $message_lower === $greeting . '!') {
                // Only reset if there's existing conversation
                if (count($conversation) > 0) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if this is a topic change
     */
    private function is_topic_change($new_message, $conversation) {
        if (count($conversation) < 2) {
            return false;
        }
        
        // Get last user message
        $last_user_message = '';
        for ($i = count($conversation) - 1; $i >= 0; $i--) {
            if (isset($conversation[$i]['role']) && $conversation[$i]['role'] === 'user') {
                $last_user_message = strtolower($conversation[$i]['content']);
                break;
            }
        }
        
        if (empty($last_user_message)) {
            return false;
        }
        
        $new_message_lower = strtolower($new_message);
        
        // Simple keyword overlap check
        $last_words = explode(' ', $last_user_message);
        $new_words = explode(' ', $new_message_lower);
        
        $common_words = array_intersect($last_words, $new_words);
        $overlap_ratio = count($common_words) / max(count($last_words), count($new_words));
        
        // If overlap is less than 30%, likely a topic change
        return $overlap_ratio < 0.3;
    }
    
    /**
     * Debug logging (only when WP_DEBUG is true)
     */
    private function log_debug($message, $data = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('LOL AI Recommender: ' . $message . ' - ' . print_r($data, true));
        }
    }
    
    /**
     * Generate session ID
     */
    private function generate_session_id() {
        return wp_generate_uuid4();
    }
    
    /**
     * Check rate limit
     */
    private function check_rate_limit($ip, $limit) {
        $cache_key = 'lol_rate_limit_' . md5($ip);
        $requests = get_transient($cache_key);
        
        if ($requests === false) {
            $requests = 0;
        }
        
        if ($requests >= $limit) {
            return false;
        }
        
        $requests++;
        set_transient($cache_key, $requests, 60); // 1 minute window
        
        return true;
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
}
