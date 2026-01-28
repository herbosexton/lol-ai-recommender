<?php
/**
 * Intent Extraction and Structured Response Schema
 */

if (!defined('ABSPATH')) {
    exit;
}

class LOL_Intent {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // No initialization needed
    }
    
    /**
     * Extract structured intent and generate blended response
     * Returns structured JSON with conversation + recommendations intent
     */
    public function extract_blended_intent($conversation_history, $available_categories = array(), $available_effects = array()) {
        $openai = LOL_OpenAI::get_instance();
        
        if (!$openai->is_configured()) {
            return $this->get_fallback_response();
        }
        
        $category_context = '';
        if (!empty($available_categories)) {
            $category_context = "\n\nAvailable categories: " . implode(', ', array_slice($available_categories, 0, 15));
        }
        
        $system_prompt = "You are a friendly, knowledgeable cannabis dispensary assistant at Legacy on Lark. Analyze the conversation and return ONLY a valid JSON object with this exact structure:

{
  \"reply\": \"A conversational, helpful response to the user's question. Answer their question naturally. If they're asking about health/safety, provide balanced guidance without medical claims. If they mention symptoms/needs (sleep, pain, anxiety, etc.), acknowledge it warmly and offer general guidance. Reflect their story briefly ('Got it—thanks for sharing...'). Keep it friendly, 2-3 short paragraphs max, avoid clinical tone.\",
  \"need_clarification\": true/false,
  \"clarifying_questions\": [\"question1\", \"question2\"],
  \"recommendation_intent\": {
    \"wants_recs\": true/false,
    \"category\": \"edible|flower|vape|tincture|topical|concentrate|preroll|unknown\",
    \"effects\": [\"sleep\", \"relax\", \"pain\", \"focus\", \"energy\", \"stress\", \"inflammation\", \"back pain\", \"anxiety\"],
    \"avoid\": [\"high_thc\", \"smoke\", \"edible\", etc.],
    \"price_max\": number or null,
    \"thc_preference\": \"low|medium|high|unknown\",
    \"cbd_preference\": \"yes|no|unknown\",
    \"notes\": \"short notes about user needs\"
  },
  \"safety_notes\": [\"start low go slow\", \"consult clinician if on meds\", etc.]
}

Rules:
- wants_recs should be true if user mentions symptoms, needs, goals, or asks \"what should I try\", \"recommend\", \"suggest\", etc.
- If user mentions age (65+, senior, elderly), set thc_preference to \"low\" and add safety notes about consulting clinician
- If user mentions \"new\", \"first time\", \"never tried\", set thc_preference to \"low\"
- effects: Extract from mentions like \"sleep\", \"pain\", \"back pain\", \"anxiety\", \"stress\", \"focus\", \"relax\", \"inflammation\"
- category: Match to available categories if mentioned
- clarifying_questions: Only include 1-2 questions if key info is missing (format preference, tolerance, etc.)
- safety_notes: Include relevant harm reduction (start low go slow, don't drive, avoid alcohol, consult clinician if on meds/conditions)
- Return ONLY the JSON, no other text" . $category_context;
        
        // Get recent conversation (last 6 messages = 3 turns)
        $recent_conversation = array_slice($conversation_history, -6);
        $conversation_text = '';
        foreach ($recent_conversation as $msg) {
            $role = isset($msg['role']) ? $msg['role'] : 'user';
            $content = isset($msg['content']) ? $msg['content'] : '';
            $conversation_text .= ucfirst($role) . ": " . $content . "\n";
        }
        
        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => "Analyze this conversation and extract intent:\n\n" . $conversation_text),
        );
        
        $response = $openai->chat_completion($messages);
        
        if (is_wp_error($response)) {
            return $this->get_fallback_response();
        }
        
        // Extract JSON from response
        $json = $response;
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\{.*\})/s', $response, $matches)) {
            $json = $matches[1];
        }
        
        $intent = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($intent)) {
            return $this->get_fallback_response();
        }
        
        // Validate and sanitize intent structure
        return $this->sanitize_intent($intent);
    }
    
    /**
     * Sanitize and validate intent structure
     */
    private function sanitize_intent($intent) {
        $sanitized = array(
            'reply' => isset($intent['reply']) ? sanitize_textarea_field($intent['reply']) : '',
            'need_clarification' => isset($intent['need_clarification']) ? (bool) $intent['need_clarification'] : false,
            'clarifying_questions' => array(),
            'recommendation_intent' => array(
                'wants_recs' => false,
                'category' => 'unknown',
                'effects' => array(),
                'avoid' => array(),
                'price_max' => null,
                'thc_preference' => 'unknown',
                'cbd_preference' => 'unknown',
                'notes' => '',
            ),
            'safety_notes' => array(),
        );
        
        if (isset($intent['clarifying_questions']) && is_array($intent['clarifying_questions'])) {
            $sanitized['clarifying_questions'] = array_map('sanitize_text_field', array_slice($intent['clarifying_questions'], 0, 2));
        }
        
        if (isset($intent['recommendation_intent']) && is_array($intent['recommendation_intent'])) {
            $rec_intent = $intent['recommendation_intent'];
            $sanitized['recommendation_intent']['wants_recs'] = isset($rec_intent['wants_recs']) ? (bool) $rec_intent['wants_recs'] : false;
            $sanitized['recommendation_intent']['category'] = isset($rec_intent['category']) ? sanitize_text_field($rec_intent['category']) : 'unknown';
            $sanitized['recommendation_intent']['effects'] = isset($rec_intent['effects']) && is_array($rec_intent['effects']) ? array_map('sanitize_text_field', $rec_intent['effects']) : array();
            $sanitized['recommendation_intent']['avoid'] = isset($rec_intent['avoid']) && is_array($rec_intent['avoid']) ? array_map('sanitize_text_field', $rec_intent['avoid']) : array();
            $sanitized['recommendation_intent']['price_max'] = isset($rec_intent['price_max']) && is_numeric($rec_intent['price_max']) ? floatval($rec_intent['price_max']) : null;
            $sanitized['recommendation_intent']['thc_preference'] = isset($rec_intent['thc_preference']) ? sanitize_text_field($rec_intent['thc_preference']) : 'unknown';
            $sanitized['recommendation_intent']['cbd_preference'] = isset($rec_intent['cbd_preference']) ? sanitize_text_field($rec_intent['cbd_preference']) : 'unknown';
            $sanitized['recommendation_intent']['notes'] = isset($rec_intent['notes']) ? sanitize_text_field($rec_intent['notes']) : '';
        }
        
        if (isset($intent['safety_notes']) && is_array($intent['safety_notes'])) {
            $sanitized['safety_notes'] = array_map('sanitize_text_field', $intent['safety_notes']);
        }
        
        return $sanitized;
    }
    
    /**
     * Fallback response when intent extraction fails
     */
    private function get_fallback_response() {
        return array(
            'reply' => __('I\'m here to help! What are you looking for today?', 'lol-ai-recommender'),
            'need_clarification' => false,
            'clarifying_questions' => array(),
            'recommendation_intent' => array(
                'wants_recs' => false,
                'category' => 'unknown',
                'effects' => array(),
                'avoid' => array(),
                'price_max' => null,
                'thc_preference' => 'unknown',
                'cbd_preference' => 'unknown',
                'notes' => '',
            ),
            'safety_notes' => array(),
        );
    }
}
