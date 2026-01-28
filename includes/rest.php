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
        
        // Extract filters from conversation
        $filters = $openai->extract_filters($conversation);
        
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
        
        // Generate AI response
        $system_prompt = "You are a helpful dispensary assistant. Recommend products naturally and conversationally. Mention 2-3 products by name and why they're good matches. Keep response under 150 words.";
        
        $product_names = array();
        foreach ($products as $product) {
            $product_names[] = $product['name'] . ' ($' . $product['price'] . ')';
        }
        
        $product_list = !empty($product_names) ? "\n\nAvailable products: " . implode(', ', array_slice($product_names, 0, 5)) : '';
        
        $ai_messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => "Customer wants: " . $filters['intent_summary'] . $product_list . "\n\nGenerate a helpful recommendation response:"),
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
