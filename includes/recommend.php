<?php
/**
 * Product Recommendation Engine
 */

if (!defined('ABSPATH')) {
    exit;
}

class LOL_Recommend {
    
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
     * Get recommended products based on filters
     */
    public function get_recommendations($filters, $top_n = 5) {
        if (!is_array($filters)) {
            return array();
        }
        
        $query_args = array(
            'post_type' => 'lol_product',
            'posts_per_page' => 100, // Get more to score and rank
            'post_status' => 'publish',
            'meta_query' => array(),
            'tax_query' => array(),
        );
        
        // Apply filters
        if (!empty($filters['category'])) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'lol_category',
                'field' => 'name',
                'terms' => $filters['category'],
                'operator' => 'IN',
            );
        }
        
        if (!empty($filters['brand'])) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'lol_brand',
                'field' => 'name',
                'terms' => $filters['brand'],
                'operator' => 'IN',
            );
        }
        
        if (!empty($filters['effects']) && is_array($filters['effects'])) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'lol_effects',
                'field' => 'name',
                'terms' => $filters['effects'],
                'operator' => 'IN',
            );
        }
        
        if (!empty($filters['price_max']) && $filters['price_max'] > 0) {
            // Note: Price comparison is approximate since price is stored as string
            // This is a simplified filter
            $query_args['meta_query'][] = array(
                'key' => '_lol_price',
                'compare' => 'EXISTS',
            );
        }
        
        // Ensure in stock if available
        $query_args['meta_query'][] = array(
            'key' => '_lol_in_stock',
            'value' => '1',
            'compare' => '=',
        );
        
        if (count($query_args['tax_query']) > 1) {
            $query_args['tax_query']['relation'] = 'AND';
        }
        
        $products = get_posts($query_args);
        
        // Score and rank products
        $scored_products = array();
        
        foreach ($products as $product) {
            $score = $this->score_product($product, $filters);
            $scored_products[] = array(
                'product' => $product,
                'score' => $score,
            );
        }
        
        // Sort by score (descending)
        usort($scored_products, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Return top N
        $top_products = array_slice($scored_products, 0, $top_n);
        
        // Format results
        $results = array();
        foreach ($top_products as $item) {
            $product = $item['product'];
            $results[] = $this->format_product_result($product, $filters);
        }
        
        return $results;
    }
    
    /**
     * Score a product based on filters
     */
    private function score_product($product, $filters) {
        $score = 0;
        
        // Base score
        $score += 10;
        
        // Category match (high weight)
        if (!empty($filters['category'])) {
            $categories = wp_get_post_terms($product->ID, 'lol_category', array('fields' => 'names'));
            foreach ($categories as $cat) {
                if (stripos($cat, $filters['category']) !== false || stripos($filters['category'], $cat) !== false) {
                    $score += 50;
                    break;
                }
            }
        }
        
        // Brand match (high weight)
        if (!empty($filters['brand'])) {
            $brands = wp_get_post_terms($product->ID, 'lol_brand', array('fields' => 'names'));
            foreach ($brands as $brand) {
                if (stripos($brand, $filters['brand']) !== false || stripos($filters['brand'], $brand) !== false) {
                    $score += 40;
                    break;
                }
            }
        }
        
        // Effects match (medium weight)
        if (!empty($filters['effects']) && is_array($filters['effects'])) {
            $effects = wp_get_post_terms($product->ID, 'lol_effects', array('fields' => 'names'));
            $matched_effects = 0;
            foreach ($filters['effects'] as $filter_effect) {
                foreach ($effects as $effect) {
                    if (stripos($effect, $filter_effect) !== false || stripos($filter_effect, $effect) !== false) {
                        $matched_effects++;
                        break;
                    }
                }
            }
            $score += $matched_effects * 30;
        }
        
        // Must-have keywords in title/description
        if (!empty($filters['must_have']) && is_array($filters['must_have'])) {
            $content = strtolower($product->post_title . ' ' . $product->post_content);
            foreach ($filters['must_have'] as $must_have) {
                if (stripos($content, strtolower($must_have)) !== false) {
                    $score += 20;
                }
            }
        }
        
        // Avoid keywords (penalty)
        if (!empty($filters['avoid']) && is_array($filters['avoid'])) {
            $content = strtolower($product->post_title . ' ' . $product->post_content);
            foreach ($filters['avoid'] as $avoid) {
                if (stripos($content, strtolower($avoid)) !== false) {
                    $score -= 30;
                }
            }
        }
        
        // Price match (bonus if within budget)
        if (!empty($filters['price_max']) && $filters['price_max'] > 0) {
            $price = get_post_meta($product->ID, '_lol_price', true);
            if (!empty($price)) {
                // Extract numeric price
                $price_num = floatval(preg_replace('/[^0-9.]/', '', $price));
                if ($price_num > 0 && $price_num <= $filters['price_max']) {
                    $score += 15;
                    // Bonus for being well under budget
                    if ($price_num < $filters['price_max'] * 0.7) {
                        $score += 5;
                    }
                } elseif ($price_num > $filters['price_max']) {
                    $score -= 20; // Penalty for over budget
                }
            }
        }
        
        // In stock bonus
        $in_stock = get_post_meta($product->ID, '_lol_in_stock', true);
        if ($in_stock === '1') {
            $score += 10;
        }
        
        // Keyword match in title/description
        $content = strtolower($product->post_title . ' ' . $product->post_content);
        if (!empty($filters['intent_summary'])) {
            $keywords = explode(' ', strtolower($filters['intent_summary']));
            foreach ($keywords as $keyword) {
                if (strlen($keyword) > 3 && stripos($content, $keyword) !== false) {
                    $score += 5;
                }
            }
        }
        
        return max(0, $score); // Ensure non-negative
    }
    
    /**
     * Format product for API response
     */
    private function format_product_result($product, $filters) {
        $remote_url = get_post_meta($product->ID, '_lol_remote_url', true);
        $price = get_post_meta($product->ID, '_lol_price', true);
        $brand = get_post_meta($product->ID, '_lol_brand', true);
        
        $categories = wp_get_post_terms($product->ID, 'lol_category', array('fields' => 'names'));
        $category = !empty($categories) ? $categories[0] : '';
        
        // Generate short reason
        $reasons = array();
        if (!empty($category) && !empty($filters['category']) && stripos($category, $filters['category']) !== false) {
            $reasons[] = __('Matches your category preference', 'lol-ai-recommender');
        }
        if (!empty($brand) && !empty($filters['brand']) && stripos($brand, $filters['brand']) !== false) {
            $reasons[] = __('From your preferred brand', 'lol-ai-recommender');
        }
        if (!empty($filters['effects'])) {
            $effects = wp_get_post_terms($product->ID, 'lol_effects', array('fields' => 'names'));
            $matched = array_intersect(array_map('strtolower', $effects), array_map('strtolower', $filters['effects']));
            if (!empty($matched)) {
                $reasons[] = __('Has desired effects', 'lol-ai-recommender');
            }
        }
        if (empty($reasons)) {
            $reasons[] = __('Popular choice', 'lol-ai-recommender');
        }
        
        $short_reason = implode(', ', array_slice($reasons, 0, 2));
        
        return array(
            'id' => $product->ID,
            'name' => $product->post_title,
            'price' => $price,
            'category' => $category,
            'brand' => $brand,
            'short_reason' => $short_reason,
            'remote_url' => $remote_url,
        );
    }
    
    /**
     * Get available categories for question generation
     */
    public function get_available_categories($limit = 20) {
        $terms = get_terms(array(
            'taxonomy' => 'lol_category',
            'hide_empty' => true,
            'number' => $limit,
        ));
        
        if (is_wp_error($terms)) {
            return array();
        }
        
        return wp_list_pluck($terms, 'name');
    }
    
    /**
     * Get available effects for question generation
     */
    public function get_available_effects($limit = 20) {
        $terms = get_terms(array(
            'taxonomy' => 'lol_effects',
            'hide_empty' => true,
            'number' => $limit,
        ));
        
        if (is_wp_error($terms)) {
            return array();
        }
        
        return wp_list_pluck($terms, 'name');
    }
}
