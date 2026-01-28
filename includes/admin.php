<?php
/**
 * Admin Settings Page
 */

if (!defined('ABSPATH')) {
    exit;
}

class LOL_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Add top-level menu for better visibility
        add_menu_page(
            __('LOL AI Recommender', 'lol-ai-recommender'),
            __('LOL Recommender', 'lol-ai-recommender'),
            'manage_options',
            'lol-settings',
            array($this, 'render_settings_page'),
            'dashicons-products',
            30
        );
        
        // Also add as submenu under Products for organization (with unique slug)
        add_submenu_page(
            'edit.php?post_type=lol_product',
            __('LOL AI Recommender Settings', 'lol-ai-recommender'),
            __('Settings', 'lol-ai-recommender'),
            'manage_options',
            'lol-product-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Dutchie URLs
        register_setting('lol_settings', 'lol_dutchie_menu_base_url', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ));
        
        register_setting('lol_settings', 'lol_dutchie_sitemap_url', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ));
        
        // OpenAI API Key (prefer env var, but allow manual entry)
        register_setting('lol_settings', 'lol_openai_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));
        
        // Sync settings
        register_setting('lol_settings', 'lol_sync_frequency', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'daily',
        ));
        
        register_setting('lol_settings', 'lol_crawl_rate_limit', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 30,
        ));
        
        register_setting('lol_settings', 'lol_max_products_per_sync', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 100,
        ));
        
        register_setting('lol_settings', 'lol_chat_rate_limit', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 10,
        ));
        
        // Add settings sections
        add_settings_section(
            'lol_dutchie_section',
            __('Dutchie Configuration', 'lol-ai-recommender'),
            array($this, 'render_dutchie_section'),
            'lol-settings'
        );
        
        add_settings_section(
            'lol_openai_section',
            __('OpenAI Configuration', 'lol-ai-recommender'),
            array($this, 'render_openai_section'),
            'lol-settings'
        );
        
        add_settings_section(
            'lol_sync_section',
            __('Sync Settings', 'lol-ai-recommender'),
            array($this, 'render_sync_section'),
            'lol-settings'
        );
        
        add_settings_section(
            'lol_status_section',
            __('Sync Status', 'lol-ai-recommender'),
            array($this, 'render_status_section'),
            'lol-settings'
        );
        
        // Add settings fields
        add_settings_field(
            'lol_dutchie_menu_base_url',
            __('Dutchie Menu Base URL', 'lol-ai-recommender'),
            array($this, 'render_text_field'),
            'lol-settings',
            'lol_dutchie_section',
            array('field' => 'lol_dutchie_menu_base_url', 'type' => 'url', 'placeholder' => 'https://domain.com/menu')
        );
        
        add_settings_field(
            'lol_dutchie_sitemap_url',
            __('Dutchie Sitemap URL', 'lol-ai-recommender'),
            array($this, 'render_text_field'),
            'lol-settings',
            'lol_dutchie_section',
            array('field' => 'lol_dutchie_sitemap_url', 'type' => 'url', 'placeholder' => 'https://domain.com/sitemap.xml')
        );
        
        add_settings_field(
            'lol_openai_api_key',
            __('OpenAI API Key', 'lol-ai-recommender'),
            array($this, 'render_openai_key_field'),
            'lol-settings',
            'lol_openai_section',
            array('field' => 'lol_openai_api_key')
        );
        
        add_settings_field(
            'lol_sync_frequency',
            __('Sync Frequency', 'lol-ai-recommender'),
            array($this, 'render_select_field'),
            'lol-settings',
            'lol_sync_section',
            array(
                'field' => 'lol_sync_frequency',
                'options' => array(
                    'hourly' => __('Hourly', 'lol-ai-recommender'),
                    'twicedaily' => __('Twice Daily', 'lol-ai-recommender'),
                    'daily' => __('Daily', 'lol-ai-recommender'),
                ),
            )
        );
        
        add_settings_field(
            'lol_crawl_rate_limit',
            __('Crawl Rate Limit (requests/minute)', 'lol-ai-recommender'),
            array($this, 'render_text_field'),
            'lol-settings',
            'lol_sync_section',
            array('field' => 'lol_crawl_rate_limit', 'type' => 'number', 'min' => 1, 'max' => 300)
        );
        
        add_settings_field(
            'lol_max_products_per_sync',
            __('Max Products Per Sync', 'lol-ai-recommender'),
            array($this, 'render_text_field'),
            'lol-settings',
            'lol_sync_section',
            array('field' => 'lol_max_products_per_sync', 'type' => 'number', 'min' => 1)
        );
        
        add_settings_field(
            'lol_chat_rate_limit',
            __('Chat Rate Limit (requests/minute/IP)', 'lol-ai-recommender'),
            array($this, 'render_text_field'),
            'lol-settings',
            'lol_sync_section',
            array('field' => 'lol_chat_rate_limit', 'type' => 'number', 'min' => 1)
        );
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if this is first-time setup
        $menu_url = get_option('lol_dutchie_menu_base_url', '');
        $sitemap_url = get_option('lol_dutchie_sitemap_url', '');
        $api_key = get_option('lol_openai_api_key', '');
        $env_api_key = getenv('OPENAI_API_KEY');
        
        // Check for API key from .env file
        $env_file_key = $this->read_env_file_key();
        
        // Check if configured (must have URLs and at least one API key source)
        $has_api_key = !empty($api_key) || !empty($env_api_key) || !empty($env_file_key);
        $has_urls = !empty($menu_url) || !empty($sitemap_url);
        $is_configured = $has_urls && $has_api_key;
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if (!$is_configured) : ?>
            <div class="notice notice-info">
                <p><strong><?php esc_html_e('Welcome!', 'lol-ai-recommender'); ?></strong></p>
                <p><?php esc_html_e('To get started, please configure the following:', 'lol-ai-recommender'); ?></p>
                <ol>
                    <li><?php esc_html_e('Set your OpenAI API key (preferably as an environment variable)', 'lol-ai-recommender'); ?></li>
                    <li><?php esc_html_e('Enter your Dutchie menu base URL and/or sitemap URL', 'lol-ai-recommender'); ?></li>
                    <li><?php esc_html_e('Click "Run Sync Now" to import products', 'lol-ai-recommender'); ?></li>
                    <li><?php esc_html_e('Add the [lol_ai_recommender] shortcode to a page', 'lol-ai-recommender'); ?></li>
                </ol>
            </div>
            <?php endif; ?>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('lol_settings');
                do_settings_sections('lol-settings');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e('Manual Sync', 'lol-ai-recommender'); ?></h2>
            <p><?php esc_html_e('Click the button below to manually trigger a product sync.', 'lol-ai-recommender'); ?></p>
            <button type="button" id="lol-manual-sync" class="button button-secondary">
                <?php esc_html_e('Run Sync Now', 'lol-ai-recommender'); ?>
            </button>
            <span id="lol-sync-status" style="margin-left: 10px;"></span>
        </div>
        <?php
    }
    
    /**
     * Render section descriptions
     */
    public function render_dutchie_section() {
        echo '<p>' . esc_html__('Configure the Dutchie menu URLs for crawling.', 'lol-ai-recommender') . '</p>';
    }
    
    public function render_openai_section() {
        $env_key = getenv('OPENAI_API_KEY');
        if ($env_key) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('OpenAI API key detected from environment variable.', 'lol-ai-recommender') . '</p></div>';
        }
        echo '<p>' . esc_html__('Enter your OpenAI API key. The plugin will first check the OPENAI_API_KEY environment variable.', 'lol-ai-recommender') . '</p>';
    }
    
    public function render_sync_section() {
        echo '<p>' . esc_html__('Configure how often and how many products to sync.', 'lol-ai-recommender') . '</p>';
    }
    
    public function render_status_section() {
        $last_sync = get_option('lol_last_sync_time');
        $last_sync_status = get_option('lol_last_sync_status', '');
        $last_sync_errors = get_option('lol_last_sync_errors', array());
        
        if ($last_sync) {
            echo '<p><strong>' . esc_html__('Last Sync:', 'lol-ai-recommender') . '</strong> ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync)) . '</p>';
        } else {
            echo '<p><strong>' . esc_html__('Last Sync:', 'lol-ai-recommender') . '</strong> ' . esc_html__('Never', 'lol-ai-recommender') . '</p>';
        }
        
        if ($last_sync_status) {
            $status_class = $last_sync_status === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($status_class) . ' inline"><p>' . esc_html(ucfirst($last_sync_status)) . '</p></div>';
        }
        
        if (!empty($last_sync_errors)) {
            echo '<details><summary>' . esc_html__('Errors', 'lol-ai-recommender') . '</summary><pre>' . esc_html(print_r($last_sync_errors, true)) . '</pre></details>';
        }
    }
    
    /**
     * Render text field
     */
    public function render_text_field($args) {
        $field = $args['field'];
        $value = get_option($field, '');
        $type = isset($args['type']) ? $args['type'] : 'text';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        $min = isset($args['min']) ? $args['min'] : '';
        $max = isset($args['max']) ? $args['max'] : '';
        
        $attrs = array();
        if ($min !== '') $attrs[] = 'min="' . esc_attr($min) . '"';
        if ($max !== '') $attrs[] = 'max="' . esc_attr($max) . '"';
        
        ?>
        <input 
            type="<?php echo esc_attr($type); ?>" 
            name="<?php echo esc_attr($field); ?>" 
            value="<?php echo esc_attr($value); ?>" 
            placeholder="<?php echo esc_attr($placeholder); ?>"
            <?php echo implode(' ', $attrs); ?>
            class="regular-text"
        />
        <?php
    }
    
    /**
     * Render select field
     */
    public function render_select_field($args) {
        $field = $args['field'];
        $value = get_option($field, '');
        $options = $args['options'];
        
        ?>
        <select name="<?php echo esc_attr($field); ?>">
            <?php foreach ($options as $opt_value => $opt_label) : ?>
                <option value="<?php echo esc_attr($opt_value); ?>" <?php selected($value, $opt_value); ?>>
                    <?php echo esc_html($opt_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
    
    /**
     * Render OpenAI key field
     */
    public function render_openai_key_field($args) {
        $field = $args['field'];
        $value = get_option($field, '');
        $env_key = getenv('OPENAI_API_KEY');
        
        if ($env_key) {
            echo '<p class="description">' . esc_html__('Using environment variable. Leave blank to use env var, or enter a different key to override.', 'lol-ai-recommender') . '</p>';
        }
        
        ?>
        <input 
            type="password" 
            name="<?php echo esc_attr($field); ?>" 
            value="<?php echo esc_attr($value); ?>" 
            class="regular-text"
            autocomplete="off"
        />
        <?php
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Check if we're on the settings page (hook format varies by WordPress version)
        // Hook could be: 'toplevel_page_lol-settings' or 'lol_product_page_lol-settings'
        if (strpos($hook, 'lol-settings') === false) {
            return;
        }
        
        wp_enqueue_script(
            'lol-admin-js',
            LOL_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            LOL_PLUGIN_VERSION,
            true
        );
        
        wp_localize_script('lol-admin-js', 'lolAdmin', array(
            'apiUrl' => rest_url('lol/v1/sync'),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }
    
    /**
     * Read API key from .env file (helper method)
     */
    private function read_env_file_key() {
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
}
