<?php
/**
 * Custom Post Type and Taxonomies
 */

if (!defined('ABSPATH')) {
    exit;
}

class LOL_CPT {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'register_cpt'));
        add_action('init', array($this, 'register_taxonomies'));
    }
    
    /**
     * Register Custom Post Type
     */
    public function register_cpt() {
        $labels = array(
            'name' => __('Products', 'lol-ai-recommender'),
            'singular_name' => __('Product', 'lol-ai-recommender'),
            'menu_name' => __('LOL Products', 'lol-ai-recommender'),
            'add_new' => __('Add New', 'lol-ai-recommender'),
            'add_new_item' => __('Add New Product', 'lol-ai-recommender'),
            'edit_item' => __('Edit Product', 'lol-ai-recommender'),
            'new_item' => __('New Product', 'lol-ai-recommender'),
            'view_item' => __('View Product', 'lol-ai-recommender'),
            'search_items' => __('Search Products', 'lol-ai-recommender'),
            'not_found' => __('No products found', 'lol-ai-recommender'),
            'not_found_in_trash' => __('No products found in trash', 'lol-ai-recommender'),
        );
        
        $args = array(
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-products',
            'query_var' => true,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => array('title', 'editor', 'thumbnail'),
            'show_in_rest' => false,
        );
        
        register_post_type('lol_product', $args);
    }
    
    /**
     * Register Taxonomies
     */
    public function register_taxonomies() {
        // Category taxonomy
        register_taxonomy('lol_category', 'lol_product', array(
            'labels' => array(
                'name' => __('Categories', 'lol-ai-recommender'),
                'singular_name' => __('Category', 'lol-ai-recommender'),
                'search_items' => __('Search Categories', 'lol-ai-recommender'),
                'all_items' => __('All Categories', 'lol-ai-recommender'),
                'edit_item' => __('Edit Category', 'lol-ai-recommender'),
                'update_item' => __('Update Category', 'lol-ai-recommender'),
                'add_new_item' => __('Add New Category', 'lol-ai-recommender'),
                'new_item_name' => __('New Category Name', 'lol-ai-recommender'),
                'menu_name' => __('Categories', 'lol-ai-recommender'),
            ),
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => false,
        ));
        
        // Brand taxonomy
        register_taxonomy('lol_brand', 'lol_product', array(
            'labels' => array(
                'name' => __('Brands', 'lol-ai-recommender'),
                'singular_name' => __('Brand', 'lol-ai-recommender'),
                'search_items' => __('Search Brands', 'lol-ai-recommender'),
                'all_items' => __('All Brands', 'lol-ai-recommender'),
                'edit_item' => __('Edit Brand', 'lol-ai-recommender'),
                'update_item' => __('Update Brand', 'lol-ai-recommender'),
                'add_new_item' => __('Add New Brand', 'lol-ai-recommender'),
                'new_item_name' => __('New Brand Name', 'lol-ai-recommender'),
                'menu_name' => __('Brands', 'lol-ai-recommender'),
            ),
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => false,
        ));
        
        // Effects taxonomy
        register_taxonomy('lol_effects', 'lol_product', array(
            'labels' => array(
                'name' => __('Effects', 'lol-ai-recommender'),
                'singular_name' => __('Effect', 'lol-ai-recommender'),
                'search_items' => __('Search Effects', 'lol-ai-recommender'),
                'all_items' => __('All Effects', 'lol-ai-recommender'),
                'edit_item' => __('Edit Effect', 'lol-ai-recommender'),
                'update_item' => __('Update Effect', 'lol-ai-recommender'),
                'add_new_item' => __('Add New Effect', 'lol-ai-recommender'),
                'new_item_name' => __('New Effect Name', 'lol-ai-recommender'),
                'menu_name' => __('Effects', 'lol-ai-recommender'),
            ),
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => false,
        ));
    }
}
