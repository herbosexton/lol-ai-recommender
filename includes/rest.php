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
     * Handle chat request with error handling wrapper
     */
    public function handle_chat($request) {
        try {
            // Rate limiting
            $ip = $this->get_client_ip();
            $rate_limit = get_option('lol_chat_rate_limit', 10);
            
            if (!$this->check_rate_limit($ip, $rate_limit)) {
                return rest_ensure_response(array(
                    'ok' => false,
                    'assistant_message' => __('Rate limit exceeded. Please wait a moment before sending another message.', 'lol-ai-recommender'),
                    'recommendations' => array(),
                    'clarifying_questions' => array(),
                    'error_type' => 'rate_limited',
                    'retry_after' => 60,
                    'session_id' => null,
                ));
            }
            
            $message = trim($request->get_param('message'));
            $session_id = $request->get_param('session_id');
            
            if (empty($message)) {
                return rest_ensure_response(array(
                    'ok' => false,
                    'assistant_message' => __('Please enter a message.', 'lol-ai-recommender'),
                    'recommendations' => array(),
                    'clarifying_questions' => array(),
                    'error_type' => 'invalid_input',
                    'retry_after' => null,
                    'session_id' => $session_id,
                ));
            }
            
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
            
            // Check age requirement (basic check)
            if ($this->mentions_underage($message)) {
                return rest_ensure_response(array(
                    'ok' => true,
                    'assistant_message' => __('I appreciate your interest, but cannabis products are only available to adults 21 and older. If you have questions about cannabis, I recommend speaking with a healthcare provider or checking educational resources.', 'lol-ai-recommender'),
                    'recommendations' => array(),
                    'clarifying_questions' => array(),
                    'error_type' => null,
                    'retry_after' => null,
                    'session_id' => $session_id,
                ));
            }
            
            // Get intent extraction helper
            $intent_helper = LOL_Intent::get_instance();
            $recommend = LOL_Recommend::get_instance();
            
            // Get available categories and effects for context
            $available_categories = $recommend->get_available_categories();
            $available_effects = $recommend->get_available_effects();
            
            // Extract blended intent (conversation + recommendation intent)
            $intent = $intent_helper->extract_blended_intent($conversation, $available_categories, $available_effects);
            
            // Get products if recommendation intent exists
            $products = array();
            if (!empty($intent['recommendation_intent']['wants_recs'])) {
                $rec_intent = $intent['recommendation_intent'];
                
                // Build filters from intent
                $filters = array();
                if ($rec_intent['category'] !== 'unknown') {
                    $filters['category'] = $rec_intent['category'];
                }
                if (!empty($rec_intent['effects'])) {
                    $filters['effects'] = $rec_intent['effects'];
                }
                if (!empty($rec_intent['price_max'])) {
                    $filters['price_max'] = $rec_intent['price_max'];
                }
                
                // Get recommendations with intent-aware scoring
                $products = $recommend->get_recommendations_with_intent($filters, $rec_intent, 5);
            }
            
            // Enhance reply with product mentions if we have products
            $final_reply = $intent['reply'];
            if (!empty($products) && !empty($intent['recommendation_intent']['wants_recs'])) {
                // Products will be shown separately, but we can mention them in reply
                $product_names = array_slice(array_column($products, 'name'), 0, 3);
                if (!empty($product_names)) {
                    $final_reply .= ' I\'ve found some products that might work well for you—check them out below.';
                }
            }
            
            // Add safety notes to reply if present
            if (!empty($intent['safety_notes'])) {
                $final_reply .= "\n\n" . __('Important:', 'lol-ai-recommender') . ' ' . implode(' ', $intent['safety_notes']);
            }
            
            // Add medical disclaimer if health-related
            if ($this->is_health_related($message)) {
                $final_reply .= "\n\n" . __('Please note: This is not medical advice. Consult with a healthcare provider, especially if you take medications or have medical conditions.', 'lol-ai-recommender');
            }
            
            // Add assistant response to conversation
            $conversation[] = array('role' => 'assistant', 'content' => $final_reply);
            
            // Save conversation state
            $this->save_conversation_state($session_id, $conversation, $final_reply);
            
            // Store user profile if extracted
            if (!empty($intent['recommendation_intent']['thc_preference']) && $intent['recommendation_intent']['thc_preference'] !== 'unknown') {
                $this->save_user_profile($session_id, array(
                    'thc_preference' => $intent['recommendation_intent']['thc_preference'],
                    'cbd_preference' => $intent['recommendation_intent']['cbd_preference'],
                ));
            }
            
            $this->log_debug('Chat response generated', array(
                'session_id' => $session_id,
                'message_count' => count($conversation),
                'user_message' => $message,
                'products_found' => count($products),
                'wants_recs' => !empty($intent['recommendation_intent']['wants_recs']),
                'reset_triggered' => $should_reset,
            ));
            
            $result = rest_ensure_response(array(
                'ok' => true,
                'assistant_message' => $final_reply,
                'recommendations' => $products,
                'clarifying_questions' => $intent['clarifying_questions'],
                'error_type' => null,
                'retry_after' => null,
                'session_id' => $session_id,
            ));
            
            // Handle errors with structured response
            if (is_wp_error($result)) {
                $error_code = $result->get_error_code();
                $error_data = $result->get_error_data();
                
                if ($error_code === 'rate_limited') {
                    $retry_after = isset($error_data['retry_after']) ? $error_data['retry_after'] : 20;
                    return rest_ensure_response(array(
                        'ok' => false,
                        'assistant_message' => __('I\'m getting a lot of requests right now. Please wait ' . $retry_after . ' seconds and try again.', 'lol-ai-recommender'),
                        'recommendations' => array(),
                        'clarifying_questions' => array(),
                        'error_type' => 'rate_limited',
                        'retry_after' => $retry_after,
                        'session_id' => $session_id,
                    ));
                }
                
                if ($error_code === 'temporary_error') {
                    $retry_after = isset($error_data['retry_after']) ? $error_data['retry_after'] : 5;
                    return rest_ensure_response(array(
                        'ok' => false,
                        'assistant_message' => __('Connection issue. Let me try again in a moment...', 'lol-ai-recommender'),
                        'recommendations' => array(),
                        'clarifying_questions' => array(),
                        'error_type' => 'temporary',
                        'retry_after' => $retry_after,
                        'session_id' => $session_id,
                    ));
                }
                
                // Generic error - return friendly fallback
                return rest_ensure_response(array(
                    'ok' => true,
                    'assistant_message' => __('Thanks for your question! I\'m having a technical issue right now, but I\'m here to help. Could you try rephrasing your question, or feel free to browse our menu directly?', 'lol-ai-recommender'),
                    'recommendations' => array(),
                    'clarifying_questions' => array(
                        __('What products are you looking for?', 'lol-ai-recommender'),
                        __('Do you have questions about cannabis?', 'lol-ai-recommender'),
                    ),
                    'error_type' => null,
                    'retry_after' => null,
                    'session_id' => $session_id,
                ));
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log_debug('Exception in handle_chat', array('error' => $e->getMessage()));
            return rest_ensure_response(array(
                'ok' => true,
                'assistant_message' => __('I\'m here to help! What can I assist you with today?', 'lol-ai-recommender'),
                'recommendations' => array(),
                'clarifying_questions' => array(),
                'error_type' => null,
                'retry_after' => null,
                'session_id' => $session_id ?? null,
            ));
        }
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
     * Check if message mentions underage
     */
    private function mentions_underage($message) {
        $message_lower = strtolower($message);
        $age_patterns = array('/\b(1[0-9]|under\s*21|underage|minor|teen)\b/i');
        
        foreach ($age_patterns as $pattern) {
            if (preg_match($pattern, $message_lower)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if message is health-related
     */
    private function is_health_related($message) {
        $message_lower = strtolower($message);
        $health_keywords = array('health', 'medical', 'condition', 'disease', 'illness', 'symptom', 'pain', 'anxiety', 'depression', 'medication', 'doctor', 'clinician', 'safe', 'bad for', 'harmful', 'side effect');
        
        foreach ($health_keywords as $keyword) {
            if (strpos($message_lower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Save user profile preferences
     */
    private function save_user_profile($session_id, $profile) {
        $profile_key = 'lol_user_profile_' . md5($session_id);
        $existing = get_transient($profile_key);
        if ($existing) {
            $profile = array_merge($existing, $profile);
        }
        set_transient($profile_key, $profile, HOUR_IN_SECONDS * 24);
    }
    
    /**
     * Get user profile preferences
     */
    private function get_user_profile($session_id) {
        $profile_key = 'lol_user_profile_' . md5($session_id);
        return get_transient($profile_key) ?: array();
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
