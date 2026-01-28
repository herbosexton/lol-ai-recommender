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
     * Generate chat completion with robust error handling
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
                'temperature' => 0.5,
                'max_tokens' => 400,
                'presence_penalty' => 0.4,
                'frequency_penalty' => 0.4,
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $error_code = $response->get_error_code();
            if (strpos($error_code, 'timeout') !== false || strpos($error_code, 'connect') !== false) {
                return new WP_Error('temporary_error', __('Connection timeout. Please try again.', 'lol-ai-recommender'), array('retry_after' => 5));
            }
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($code === 429) {
            // Rate limit - extract retry-after if available
            $retry_after = wp_remote_retrieve_header($response, 'retry-after');
            $retry_seconds = $retry_after ? intval($retry_after) : 20;
            return new WP_Error('rate_limited', __('Rate limit exceeded. Please wait a moment.', 'lol-ai-recommender'), array('retry_after' => $retry_seconds));
        }
        
        if ($code !== 200) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : __('OpenAI API error', 'lol-ai-recommender');
            $error_type = isset($data['error']['type']) ? $data['error']['type'] : 'unknown';
            
            // Handle content moderation
            if ($error_type === 'content_filter' || strpos(strtolower($error_message), 'moderation') !== false) {
                // Return safe fallback instead of error
                return __('I understand you have questions. For health and safety guidance, I recommend consulting with a healthcare provider. I can help you find products—what are you looking for?', 'lol-ai-recommender');
            }
            
            return new WP_Error('openai_api_error', $error_message, array('error_type' => $error_type));
        }
        
        if (!isset($data['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Invalid response from OpenAI', 'lol-ai-recommender'));
        }
        
        return $data['choices'][0]['message']['content'];
    }
    
    /**
     * Extract structured filters from conversation
     */
    public function extract_filters($conversation_history, $available_categories = array(), $available_brands = array()) {
        if (!$this->is_configured()) {
            return new WP_Error('no_api_key', __('OpenAI API key not configured', 'lol-ai-recommender'));
        }
        
        $category_context = '';
        if (!empty($available_categories)) {
            $category_context = "\n\nAvailable product categories: " . implode(', ', array_slice($available_categories, 0, 20));
        }
        
        $brand_context = '';
        if (!empty($available_brands)) {
            $brand_context = "\n\nAvailable brands: " . implode(', ', array_slice($available_brands, 0, 20));
        }
        
        $system_prompt = "You are a helpful assistant that extracts product search criteria from customer conversations for a cannabis dispensary. Analyze the conversation and return ONLY a valid JSON object with this exact structure:

{
  \"intent_summary\": \"Brief summary of what the customer wants, including desired effects, use case, and preferences\",
  \"filters\": {
    \"category\": \"product category if mentioned (e.g., Flower, Vapes, Edibles, Prerolls, Concentrates, Tinctures, Topicals). Match to available categories if possible, or empty string\",
    \"brand\": \"brand name if mentioned, or empty string\",
    \"effects\": [\"effect1\", \"effect2\"],
    \"price_max\": 0
  },
  \"must_have\": [\"required feature 1\", \"required feature 2\"],
  \"avoid\": [\"thing to avoid 1\"],
  \"top_n\": 5
}

Rules:
- Categories: Common cannabis categories include Flower, Vapes, Edibles, Prerolls, Concentrates, Tinctures, Topicals, Drinks, Moon Rocks, Accessories, Bundles, Chocolates
- If customer mentions \"flower\", \"bud\", \"weed\", \"herb\" → category: \"Flower\"
- If customer mentions \"vape\", \"cart\", \"cartridge\" → category: \"Vapes\"
- If customer mentions \"edible\", \"gummy\", \"chocolate\", \"cookie\" → category: \"Edibles\"
- Effects: Extract from mentions like \"relaxing\", \"energizing\", \"sleep\", \"pain relief\", \"anxiety\", \"focus\", \"creative\", \"euphoric\", \"calming\", \"uplifting\"
- If price/budget mentioned, set price_max (number only, no currency)
- If no price mentioned, set price_max to 0
- top_n should be 3-10 based on how specific the request is
- Return ONLY the JSON, no other text" . $category_context . $brand_context;

        // Focus on recent conversation (last 6 messages = 3 turns) for filter extraction
        $recent_history = array_slice($conversation_history, -6);
        
        $conversation_text = '';
        foreach ($recent_history as $msg) {
            $role = isset($msg['role']) ? $msg['role'] : 'user';
            $content = isset($msg['content']) ? $msg['content'] : '';
            $conversation_text .= ucfirst($role) . ": " . $content . "\n";
        }
        
        // Emphasize the LATEST user message
        $latest_user_message = '';
        for ($i = count($recent_history) - 1; $i >= 0; $i--) {
            if (isset($recent_history[$i]['role']) && $recent_history[$i]['role'] === 'user') {
                $latest_user_message = $recent_history[$i]['content'];
                break;
            }
        }
        
        $extraction_prompt = "Extract filters from this conversation. Pay special attention to the LATEST user message: \"$latest_user_message\"\n\nFull conversation:\n" . $conversation_text;
        
        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $extraction_prompt),
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
     * Generate suggested prompts from CONSUMER perspective (questions a customer might ask).
     * These are example prompts the user can click to ask the AI, NOT questions the AI asks the user.
     */
    public function generate_questions($conversation_history, $available_categories = array(), $available_effects = array()) {
        if (!$this->is_configured()) {
            return $this->get_default_consumer_prompts($available_categories);
        }
        
        $system_prompt = "You are a cannabis dispensary assistant. Generate 4-5 example questions or prompts that a CUSTOMER might type to ask the dispensary. These are suggestions for what the customer could ask next (consumer perspective), NOT questions you would ask them. Each should be something a shopper would say, e.g. 'What do you recommend for relaxation?', 'Do you have flower for sleep?', 'I need something for focus'. Return ONLY a JSON array of strings. No other text. Example format: [\"What flower do you recommend for relaxation?\", \"Show me edibles for sleep\"]";

        $conversation_text = '';
        foreach ($conversation_history as $msg) {
            $role = isset($msg['role']) ? $msg['role'] : 'user';
            $content = isset($msg['content']) ? $msg['content'] : '';
            $conversation_text .= ucfirst($role) . ": " . $content . "\n";
        }
        
        $context = '';
        if (!empty($available_categories)) {
            $context .= "Available product categories we carry: " . implode(', ', array_slice($available_categories, 0, 12)) . "\n";
        }
        if (!empty($available_effects)) {
            $context .= "Effects we have products for: " . implode(', ', array_slice($available_effects, 0, 10)) . "\n";
        }
        
        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $context . "\nConversation so far:\n" . $conversation_text . "\n\nGenerate 4-5 short example prompts a customer might type next (things they could ask us):"),
        );
        
        $response = $this->chat_completion($messages, 'gpt-4o-mini');
        
        if (is_wp_error($response)) {
            return $this->get_default_consumer_prompts($available_categories);
        }
        
        $json = $response;
        if (preg_match('/\[.*\]/s', $response, $matches)) {
            $json = $matches[0];
        }
        
        $questions = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($questions)) {
            return $this->get_default_consumer_prompts($available_categories);
        }
        
        return array_slice($questions, 0, 5);
    }
    
    /**
     * Default consumer-perspective prompts when API is unavailable or returns invalid data.
     */
    private function get_default_consumer_prompts($available_categories = array()) {
        $defaults = array(
            __('What do you recommend for relaxation?', 'lol-ai-recommender'),
            __('Do you have flower for sleep?', 'lol-ai-recommender'),
            __('I\'m looking for something for focus', 'lol-ai-recommender'),
            __('What edibles do you have?', 'lol-ai-recommender'),
            __('Show me vapes for daytime', 'lol-ai-recommender'),
        );
        return array_slice($defaults, 0, 5);
    }
}
