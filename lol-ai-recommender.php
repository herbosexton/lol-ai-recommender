<?php
/**
 * Plugin Name: LOL AI Recommender
 * Plugin URI: https://example.com/lol-ai-recommender
 * Description: AI-powered product recommendation chatbot for Dutchie-hosted dispensary menus. Crawls sitemap, stores products locally, and provides interactive recommendations.
 * Version: 1.0.0
 * Author: Herbert Sexton
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lol-ai-recommender
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LOL_PLUGIN_VERSION', '1.0.0');
define('LOL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LOL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LOL_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class LOL_AI_Recommender {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Load plugin files
        add_action('plugins_loaded', array($this, 'load_files'));
        
        // Initialize plugin
        add_action('init', array($this, 'init'));
    }
    
    /**
     * Load required files
     */
    public function load_files() {
        require_once LOL_PLUGIN_DIR . 'includes/cpt.php';
        require_once LOL_PLUGIN_DIR . 'includes/admin.php';
        require_once LOL_PLUGIN_DIR . 'includes/crawler.php';
        require_once LOL_PLUGIN_DIR . 'includes/parser.php';
        require_once LOL_PLUGIN_DIR . 'includes/sync.php';
        require_once LOL_PLUGIN_DIR . 'includes/openai.php';
        require_once LOL_PLUGIN_DIR . 'includes/recommend.php';
        require_once LOL_PLUGIN_DIR . 'includes/rest.php';
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('lol-ai-recommender', false, dirname(LOL_PLUGIN_BASENAME) . '/languages');
        
        // Initialize components
        LOL_CPT::get_instance();
        LOL_Admin::get_instance();
        LOL_Sync::get_instance();
        LOL_REST::get_instance();
        
        // Register shortcode
        add_shortcode('lol_ai_recommender', array($this, 'render_chat_shortcode'));
        
        // Enqueue assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }
    
    /**
     * Render chat shortcode
     */
    public function render_chat_shortcode($atts) {
        $atts = shortcode_atts(array(
            'title' => __('AI Product Recommender', 'lol-ai-recommender'),
        ), $atts, 'lol_ai_recommender');
        
        ob_start();
        ?>
        <div id="lol-chat-container" class="lol-chat-container">
            <div class="lol-chat-header">
                <h3><?php echo esc_html($atts['title']); ?></h3>
            </div>
            <div id="lol-chat-messages" class="lol-chat-messages"></div>
            <div class="lol-chat-input-container">
                <input 
                    type="text" 
                    id="lol-chat-input" 
                    class="lol-chat-input" 
                    placeholder="<?php esc_attr_e('Ask about products...', 'lol-ai-recommender'); ?>"
                    autocomplete="off"
                />
                <button id="lol-chat-send" class="lol-chat-send"><?php esc_html_e('Send', 'lol-ai-recommender'); ?></button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        if (!is_singular()) {
            return;
        }
        
        // Check if shortcode is present
        $post = get_post();
        if (!$post) {
            return;
        }
        
        // Check post content and also check if shortcode might be in widgets/blocks
        if (!has_shortcode($post->post_content, 'lol_ai_recommender') && 
            strpos($post->post_content, '[lol_ai_recommender') === false) {
            return;
        }
        
        wp_enqueue_style(
            'lol-chat-css',
            LOL_PLUGIN_URL . 'assets/css/chat.css',
            array(),
            LOL_PLUGIN_VERSION
        );
        
        wp_enqueue_script(
            'lol-chat-js',
            LOL_PLUGIN_URL . 'assets/js/chat.js',
            array('jquery'),
            LOL_PLUGIN_VERSION,
            true
        );
        
        wp_localize_script('lol-chat-js', 'lolChat', array(
            'apiUrl' => rest_url('lol/v1/chat'),
            'nonce' => wp_create_nonce('wp_rest'),
            'strings' => array(
                'sending' => __('Sending...', 'lol-ai-recommender'),
                'error' => __('An error occurred. Please try again.', 'lol-ai-recommender'),
            ),
        ));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Register CPT and taxonomies
        require_once LOL_PLUGIN_DIR . 'includes/cpt.php';
        LOL_CPT::get_instance();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Schedule sync cron
        if (!wp_next_scheduled('lol_sync_products')) {
            $frequency = get_option('lol_sync_frequency', 'daily');
            $schedule = $this->get_cron_schedule($frequency);
            wp_schedule_event(time(), $schedule, 'lol_sync_products');
        }
        
        // Set default options
        add_option('lol_sync_frequency', 'daily');
        add_option('lol_crawl_rate_limit', 30); // requests per minute
        add_option('lol_max_products_per_sync', 100);
        add_option('lol_chat_rate_limit', 10); // requests per minute per IP
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron
        wp_clear_scheduled_hook('lol_sync_products');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Get cron schedule name
     */
    private function get_cron_schedule($frequency) {
        $schedules = array(
            'hourly' => 'hourly',
            'twicedaily' => 'twicedaily',
            'daily' => 'daily',
        );
        return isset($schedules[$frequency]) ? $schedules[$frequency] : 'daily';
    }
}

// Initialize plugin
LOL_AI_Recommender::get_instance();
