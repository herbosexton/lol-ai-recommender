<?php
/**
 * Sync Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class LOL_Sync {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('lol_sync_products', array($this, 'sync_products_cron'));
    }
    
    /**
     * Sync products (main entry point)
     */
    public function sync_products($limit = null) {
        $errors = array();
        $synced = 0;
        $skipped = 0;
        
        // Get settings
        $sitemap_url = get_option('lol_dutchie_sitemap_url', '');
        if (empty($sitemap_url)) {
            // Try to build sitemap URL from menu base URL
            $menu_base = get_option('lol_dutchie_menu_base_url', '');
            if (!empty($menu_base)) {
                $parsed = parse_url($menu_base);
                
                // Validate parsed URL has required components
                if (!isset($parsed['scheme']) || !isset($parsed['host'])) {
                    $errors[] = sprintf(__('Invalid menu base URL format: %s', 'lol-ai-recommender'), esc_html($menu_base));
                    update_option('lol_last_sync_status', 'error');
                    update_option('lol_last_sync_errors', $errors);
                    return array('success' => false, 'errors' => $errors);
                }
                
                $base_url = $parsed['scheme'] . '://' . $parsed['host'];
                
                // Try common sitemap locations
                $possible_sitemaps = array(
                    $base_url . '/sitemap.xml',
                    $base_url . '/sitemap_index.xml',
                    $base_url . '/sitemaps/sitemap.xml',
                );
                
                // Test which one exists
                foreach ($possible_sitemaps as $test_url) {
                    $response = wp_remote_head($test_url, array('timeout' => 5));
                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        $sitemap_url = $test_url;
                        break;
                    }
                }
                
                // Fallback to default
                if (empty($sitemap_url)) {
                    $sitemap_url = $base_url . '/sitemap.xml';
                }
            }
        }
        
        if (empty($sitemap_url)) {
            $errors[] = __('Sitemap URL not configured', 'lol-ai-recommender');
            update_option('lol_last_sync_status', 'error');
            update_option('lol_last_sync_errors', $errors);
            return array('success' => false, 'errors' => $errors);
        }
        
        // Get max products limit
        if ($limit === null) {
            $limit = get_option('lol_max_products_per_sync', 100);
        }
        
        // Fetch sitemap URLs
        $crawler = LOL_Crawler::get_instance();
        $product_urls = $crawler->fetch_sitemap_urls($sitemap_url);
        
        if (is_wp_error($product_urls)) {
            $errors[] = $product_urls->get_error_message();
            update_option('lol_last_sync_status', 'error');
            update_option('lol_last_sync_errors', $errors);
            return array('success' => false, 'errors' => $errors);
        }
        
        // Limit URLs
        $product_urls = array_slice($product_urls, 0, $limit);
        
        // Process each URL
        $parser = LOL_Parser::get_instance();
        
        foreach ($product_urls as $url) {
            try {
                // Check if product already exists
                $existing = $this->find_product_by_url($url);
                
                // Check if recently synced (within last hour)
                if ($existing) {
                    $last_synced = get_post_meta($existing->ID, '_lol_last_synced_at', true);
                    if ($last_synced && (time() - $last_synced) < HOUR_IN_SECONDS) {
                        $skipped++;
                        continue;
                    }
                }
                
                // Fetch product page
                $page_data = $crawler->fetch_product_page($url);
                
                if (is_wp_error($page_data)) {
                    $errors[] = sprintf(__('Failed to fetch %s: %s', 'lol-ai-recommender'), $url, $page_data->get_error_message());
                    continue;
                }
                
                // Parse product data
                $product_data = $parser->parse_product($page_data['body'], $url);
                
                // Skip if no name (invalid product)
                if (empty($product_data['name'])) {
                    $skipped++;
                    continue;
                }
                
                // Save/update product
                $this->save_product($product_data, $existing);
                $synced++;
                
                // Rate limiting delay
                usleep(2000000); // 2 seconds between products
                
            } catch (Exception $e) {
                $errors[] = sprintf(__('Error processing %s: %s', 'lol-ai-recommender'), $url, $e->getMessage());
            }
        }
        
        // Mark products not seen in sitemap for 30+ days as inactive
        $this->mark_inactive_products($product_urls);
        
        // Update sync status
        update_option('lol_last_sync_time', time());
        update_option('lol_last_sync_status', empty($errors) ? 'success' : 'partial');
        update_option('lol_last_sync_errors', $errors);
        update_option('lol_last_sync_count', $synced);
        
        return array(
            'success' => true,
            'synced' => $synced,
            'skipped' => $skipped,
            'errors' => $errors,
        );
    }
    
    /**
     * Find product by remote URL
     */
    private function find_product_by_url($url) {
        $posts = get_posts(array(
            'post_type' => 'lol_product',
            'meta_key' => '_lol_remote_url',
            'meta_value' => $url,
            'posts_per_page' => 1,
            'post_status' => 'any',
        ));
        
        return !empty($posts) ? $posts[0] : null;
    }
    
    /**
     * Save product to database
     */
    private function save_product($product_data, $existing_post = null) {
        $post_data = array(
            'post_type' => 'lol_product',
            'post_title' => $product_data['name'],
            'post_content' => $product_data['description'],
            'post_status' => 'publish',
        );
        
        if ($existing_post) {
            $post_data['ID'] = $existing_post->ID;
            $post_id = wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }
        
        if (is_wp_error($post_id)) {
            throw new Exception($post_id->get_error_message());
        }
        
        // Save meta fields
        update_post_meta($post_id, '_lol_remote_url', $product_data['remote_url']);
        update_post_meta($post_id, '_lol_remote_id', $product_data['remote_id']);
        update_post_meta($post_id, '_lol_brand', $product_data['brand']);
        update_post_meta($post_id, '_lol_price', $product_data['price']);
        update_post_meta($post_id, '_lol_thc', $product_data['thc']);
        update_post_meta($post_id, '_lol_cbd', $product_data['cbd']);
        update_post_meta($post_id, '_lol_image_url', $product_data['image_url']);
        update_post_meta($post_id, '_lol_in_stock', $product_data['in_stock'] ? '1' : '0');
        update_post_meta($post_id, '_lol_tags', $product_data['tags']);
        update_post_meta($post_id, '_lol_effects', $product_data['effects']);
        update_post_meta($post_id, '_lol_flavors', $product_data['flavors']);
        update_post_meta($post_id, '_lol_source', 'SITEMAP_CRAWL');
        update_post_meta($post_id, '_lol_last_seen_at', time());
        update_post_meta($post_id, '_lol_last_synced_at', time());
        
        // Set taxonomies
        if (!empty($product_data['category'])) {
            $category_ids = $this->get_or_create_term('lol_category', $product_data['category']);
            if (!empty($category_ids)) {
                wp_set_object_terms($post_id, $category_ids, 'lol_category');
            }
        }
        
        if (!empty($product_data['brand'])) {
            $brand_ids = $this->get_or_create_term('lol_brand', $product_data['brand']);
            if (!empty($brand_ids)) {
                wp_set_object_terms($post_id, $brand_ids, 'lol_brand');
            }
        }
        
        if (!empty($product_data['effects'])) {
            $effect_ids = array();
            foreach ($product_data['effects'] as $effect) {
                $ids = $this->get_or_create_term('lol_effects', $effect);
                $effect_ids = array_merge($effect_ids, $ids);
            }
            if (!empty($effect_ids)) {
                wp_set_object_terms($post_id, $effect_ids, 'lol_effects');
            }
        }
        
        // Set featured image if image URL provided
        if (!empty($product_data['image_url']) && !has_post_thumbnail($post_id)) {
            $this->set_featured_image_from_url($post_id, $product_data['image_url']);
        }
        
        return $post_id;
    }
    
    /**
     * Get or create taxonomy term
     */
    private function get_or_create_term($taxonomy, $name) {
        if (empty($name)) {
            return array();
        }
        
        // Handle comma-separated categories
        $names = array_map('trim', explode(',', $name));
        $term_ids = array();
        
        foreach ($names as $term_name) {
            $term = get_term_by('name', $term_name, $taxonomy);
            
            if (!$term) {
                $term_result = wp_insert_term($term_name, $taxonomy);
                if (!is_wp_error($term_result)) {
                    $term_ids[] = $term_result['term_id'];
                }
            } else {
                $term_ids[] = $term->term_id;
            }
        }
        
        return $term_ids;
    }
    
    /**
     * Set featured image from URL
     */
    private function set_featured_image_from_url($post_id, $image_url) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            return false;
        }
        
        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp,
        );
        
        $id = media_handle_sideload($file_array, $post_id);
        
        if (is_wp_error($id)) {
            @unlink($tmp);
            return false;
        }
        
        set_post_thumbnail($post_id, $id);
        return true;
    }
    
    /**
     * Mark products not seen in sitemap as inactive
     */
    private function mark_inactive_products($current_urls) {
        $url_set = array_flip($current_urls);
        $thirty_days_ago = time() - (DAY_IN_SECONDS * 30);
        
        $all_products = get_posts(array(
            'post_type' => 'lol_product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_lol_last_seen_at',
                    'value' => $thirty_days_ago,
                    'compare' => '<',
                ),
            ),
        ));
        
        foreach ($all_products as $product) {
            $remote_url = get_post_meta($product->ID, '_lol_remote_url', true);
            
            // If URL not in current sitemap, mark as inactive
            if (!isset($url_set[$remote_url])) {
                update_post_meta($product->ID, '_lol_in_stock', '0');
            }
        }
    }
    
    /**
     * Cron handler
     */
    public function sync_products_cron() {
        $this->sync_products();
    }
}
