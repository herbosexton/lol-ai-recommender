<?php
/**
 * OpenAI Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class LOL_OpenAI {
    
    private static $instance = null;
    private $api_key = '';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Get API key in order of priority:
        // 1. Environment variable (server-level)
        // 2. wp-config.php constant
        // 3. .env file in plugin directory
        // 4. Plugin option (settings page)
        
        $this->api_key = getenv('OPENAI_API_KEY');
        
        if (empty($this->api_key) && defined('OPENAI_API_KEY')) {
            $this->api_key = OPENAI_API_KEY;
        }
        
        if (empty($this->api_key)) {
            $this->api_key = $this->read_env_file();
        }
        
        if (empty($this->api_key)) {
            $this->api_key = get_option('lol_openai_api_key', '');
        }
    }
    
    /**
     * Read .env file from plugin directory
     */
    private function read_env_file() {
        $env_file = LOL_PLUGIN_DIR . '.env';
        
        if (!file_exists($env_file) || !is_readable($env_file)) {
            return '';
        }
        
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE format
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                $value = trim($value, '"\'');
                
                if ($key === 'OPENAI_API_KEY') {
                    return $value;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Get API key
     */
    public function get_api_key() {
        return $this->api_key;
    }
    
    /**
     * Check if API key is configured
     */
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    /**
     * Generate chat completion
     */
    public function chat_completion($messages, $model = 'gpt-4o-mini') {
        if (!$this->is_configured()) {
            return new WP_Error('no_api_key', __('OpenAI API key not configured', 'lol-ai-recommender'));
        }
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 1000,
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($code !== 200) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : __('OpenAI API error', 'lol-ai-recommender');
            return new WP_Error('openai_api_error', $error_message);
        }
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Invalid response from OpenAI', 'lol-ai-recommender'));
        }
        
        return $data['choices'][0]['message']['content'];
    }
    
    /**
     * Extract structured filters from conversation
     */
    public function extract_filters($conversation_history) {
        if (!$this->is_configured()) {
            return new WP_Error('no_api_key', __('OpenAI API key not configured', 'lol-ai-recommender'));
        }
        
        $system_prompt = "You are a helpful assistant that extracts product search criteria from customer conversations. Analyze the conversation and return ONLY a valid JSON object with this exact structure:

{
  \"intent_summary\": \"Brief summary of what the customer wants\",
  \"filters\": {
    \"category\": \"product category if mentioned, or empty string\",
    \"brand\": \"brand name if mentioned, or empty string\",
    \"effects\": [\"effect1\", \"effect2\"],
    \"price_max\": 0
  },
  \"must_have\": [\"required feature 1\", \"required feature 2\"],
  \"avoid\": [\"thing to avoid 1\"],
  \"top_n\": 5
}

Rules:
- If price/budget mentioned, set price_max (number only, no currency)
- If no price mentioned, set price_max to 0
- Extract effects from mentions like \"relaxing\", \"energizing\", \"sleep\", \"pain relief\", etc.
- top_n should be 3-10 based on how specific the request is
- Return ONLY the JSON, no other text";

        $conversation_text = '';
        foreach ($conversation_history as $msg) {
            $role = isset($msg['role']) ? $msg['role'] : 'user';
            $content = isset($msg['content']) ? $msg['content'] : '';
            $conversation_text .= ucfirst($role) . ": " . $content . "\n";
        }
        
        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => "Extract filters from this conversation:\n\n" . $conversation_text),
        );
        
        $response = $this->chat_completion($messages, 'gpt-4o-mini');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Extract JSON from response (might have markdown code blocks)
        $json = $response;
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\{.*\})/s', $response, $matches)) {
            $json = $matches[1];
        }
        
        $filters = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_parse_error', __('Failed to parse filters JSON', 'lol-ai-recommender'));
        }
        
        return $filters;
    }
    
    /**
     * Generate follow-up questions
     */
    public function generate_questions($conversation_history, $available_categories = array(), $available_effects = array()) {
        if (!$this->is_configured()) {
            return array();
        }
        
        $system_prompt = "You are a helpful dispensary assistant. Based on the conversation, generate 2-5 clarifying questions to better understand what the customer wants. Return questions as a JSON array of strings, e.g. [\"Question 1?\", \"Question 2?\"]";

        $conversation_text = '';
        foreach ($conversation_history as $msg) {
            $role = isset($msg['role']) ? $msg['role'] : 'user';
            $content = isset($msg['content']) ? $msg['content'] : '';
            $conversation_text .= ucfirst($role) . ": " . $content . "\n";
        }
        
        $context = '';
        if (!empty($available_categories)) {
            $context .= "Available categories: " . implode(', ', array_slice($available_categories, 0, 10)) . "\n";
        }
        if (!empty($available_effects)) {
            $context .= "Available effects: " . implode(', ', array_slice($available_effects, 0, 10)) . "\n";
        }
        
        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $context . "\nConversation:\n" . $conversation_text . "\n\nGenerate clarifying questions:"),
        );
        
        $response = $this->chat_completion($messages, 'gpt-4o-mini');
        
        if (is_wp_error($response)) {
            return array();
        }
        
        // Extract JSON array
        $json = $response;
        if (preg_match('/\[.*\]/s', $response, $matches)) {
            $json = $matches[0];
        }
        
        $questions = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($questions)) {
            return array();
        }
        
        return array_slice($questions, 0, 5); // Limit to 5 questions
    }
}
