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
        
        $message = $request->get_param('message');
        $session_id = $request->get_param('session_id');
        
        if (empty($session_id)) {
            $session_id = $this->generate_session_id();
        }
        
        // Get conversation history
        $conversation = $this->get_conversation_history($session_id);
        
        // Add user message
        $conversation[] = array('role' => 'user', 'content' => $message);
        
        // Check if we need to ask questions
        $openai = LOL_OpenAI::get_instance();
        $recommend = LOL_Recommend::get_instance();
        
        $should_ask_questions = count($conversation) < 4; // Ask questions in first few exchanges
        
        if ($should_ask_questions) {
            // Generate follow-up questions
            $categories = $recommend->get_available_categories();
            $effects = $recommend->get_available_effects();
            $questions = $openai->generate_questions($conversation, $categories, $effects);
            
            if (!empty($questions)) {
                // Save conversation
                $this->save_conversation_history($session_id, $conversation);
                
                return rest_ensure_response(array(
                    'response' => $questions[0], // Return first question
                    'questions' => $questions,
                    'products' => array(),
                    'session_id' => $session_id,
                ));
            }
        }
        
        // Get available categories and brands for context
        $available_categories = $recommend->get_available_categories();
        $available_brands = $recommend->get_available_brands();
        
        // Extract filters from conversation
        $filters = $openai->extract_filters($conversation, $available_categories, $available_brands);
        
        if (is_wp_error($filters)) {
            // Fallback: simple keyword search
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
        
        // Generate AI response with category context
        $category_info = !empty($filters['filters']['category']) ? "\n\nCustomer is interested in: " . $filters['filters']['category'] : '';
        $effects_info = !empty($filters['filters']['effects']) ? "\n\nDesired effects: " . implode(', ', $filters['filters']['effects']) : '';
        
        $system_prompt = "You are a helpful and knowledgeable cannabis dispensary assistant. Recommend products naturally and conversationally based on the customer's needs. Mention 2-3 specific products by name, their category, and explain why they're good matches based on the customer's preferences. Be friendly, informative, and helpful. Keep response under 200 words.";
        
        $ai_messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => "Customer wants: " . $filters['intent_summary'] . $category_info . $effects_info . $product_list . "\n\nGenerate a helpful, personalized recommendation response:"),
        );
        
        $ai_response = $openai->chat_completion($ai_messages);
        
        if (is_wp_error($ai_response)) {
            // Fallback response
            $ai_response = __('Based on your preferences, here are some great options:', 'lol-ai-recommender');
        }
        
        // Add assistant response to conversation
        $conversation[] = array('role' => 'assistant', 'content' => $ai_response);
        
        // Save conversation
        $this->save_conversation_history($session_id, $conversation);
        
        return rest_ensure_response(array(
            'response' => $ai_response,
            'products' => $products,
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
     * Save conversation history to transient
     */
    private function save_conversation_history($session_id, $conversation) {
        $cache_key = 'lol_conversation_' . md5($session_id);
        // Keep last 20 messages
        $conversation = array_slice($conversation, -20);
        set_transient($cache_key, $conversation, HOUR_IN_SECONDS * 2);
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
