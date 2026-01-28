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
     * Get recommended products with intent-aware scoring
     */
    public function get_recommendations_with_intent($filters, $rec_intent, $top_n = 5) {
        $products = $this->get_recommendations($filters, $top_n * 2); // Get more to re-score
        
        // Re-score products based on intent preferences
        $scored_products = array();
        foreach ($products as $product) {
            $score = $this->score_product_with_intent($product, $rec_intent);
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
        return array_column($top_products, 'product');
    }
    
    /**
     * Score product based on intent preferences
     */
    private function score_product_with_intent($product, $rec_intent) {
        $score = isset($product['score']) ? $product['score'] : 0;
        
        // Boost for THC preference match
        if (!empty($rec_intent['thc_preference']) && $rec_intent['thc_preference'] !== 'unknown') {
            $thc = isset($product['thc']) ? floatval($product['thc']) : null;
            if ($thc !== null) {
                if ($rec_intent['thc_preference'] === 'low' && $thc < 15) {
                    $score += 30;
                } elseif ($rec_intent['thc_preference'] === 'medium' && $thc >= 15 && $thc <= 25) {
                    $score += 30;
                } elseif ($rec_intent['thc_preference'] === 'high' && $thc > 25) {
                    $score += 30;
                }
            }
        }
        
        // Boost for CBD preference
        if (!empty($rec_intent['cbd_preference']) && $rec_intent['cbd_preference'] === 'yes') {
            $cbd = isset($product['cbd']) ? floatval($product['cbd']) : 0;
            if ($cbd > 0) {
                $score += 25;
            }
        }
        
        // Penalize for avoid list
        if (!empty($rec_intent['avoid']) && is_array($rec_intent['avoid'])) {
            foreach ($rec_intent['avoid'] as $avoid_term) {
                $product_text = strtolower($product['name'] . ' ' . (isset($product['description']) ? $product['description'] : ''));
                if (strpos($product_text, strtolower($avoid_term)) !== false) {
                    $score -= 50;
                }
            }
        }
        
        return $score;
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
        
        // Keyword match in title/description (enhanced)
        $content = strtolower($product->post_title . ' ' . $product->post_content);
        if (!empty($filters['intent_summary'])) {
            $keywords = explode(' ', strtolower($filters['intent_summary']));
            foreach ($keywords as $keyword) {
                if (strlen($keyword) > 3 && stripos($content, $keyword) !== false) {
                    $score += 5;
                }
            }
        }
        
        // Description match bonus (if description contains relevant keywords)
        if (!empty($filters['must_have']) && is_array($filters['must_have'])) {
            foreach ($filters['must_have'] as $must_have) {
                if (stripos($content, strtolower($must_have)) !== false) {
                    $score += 15; // Higher weight for must-have features
                }
            }
        }
        
        // Category description match (if category has descriptive terms)
        $categories = wp_get_post_terms($product->ID, 'lol_category', array('fields' => 'names'));
        $category_text = implode(' ', array_map('strtolower', $categories));
        if (!empty($filters['intent_summary']) && !empty($category_text)) {
            // Check if intent mentions category-related terms
            $category_keywords = array('flower', 'bud', 'vape', 'edible', 'gummy', 'preroll', 'concentrate', 'tincture', 'topical');
            foreach ($category_keywords as $cat_keyword) {
                if (stripos($filters['intent_summary'], $cat_keyword) !== false && stripos($category_text, $cat_keyword) !== false) {
                    $score += 20; // Strong match for category mentions
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
        $description = $product->post_content;
        
        $categories = wp_get_post_terms($product->ID, 'lol_category', array('fields' => 'names'));
        $category = !empty($categories) ? $categories[0] : '';
        
        $effects = wp_get_post_terms($product->ID, 'lol_effects', array('fields' => 'names'));
        
        // Generate short reason with more detail
        $reasons = array();
        if (!empty($category) && !empty($filters['category']) && stripos($category, $filters['category']) !== false) {
            $reasons[] = sprintf(__('Matches your %s category preference', 'lol-ai-recommender'), $category);
        }
        if (!empty($brand) && !empty($filters['brand']) && stripos($brand, $filters['brand']) !== false) {
            $reasons[] = sprintf(__('From your preferred brand: %s', 'lol-ai-recommender'), $brand);
        }
        if (!empty($filters['effects'])) {
            $matched = array_intersect(array_map('strtolower', $effects), array_map('strtolower', $filters['effects']));
            if (!empty($matched)) {
                $reasons[] = sprintf(__('Provides %s effects', 'lol-ai-recommender'), implode(' and ', array_slice($matched, 0, 2)));
            }
        }
        if (!empty($description) && strlen($description) > 20) {
            // Include a snippet of description if available
            $desc_snippet = wp_trim_words($description, 15);
            if (strlen($desc_snippet) > 0) {
                $reasons[] = $desc_snippet;
            }
        }
        if (empty($reasons)) {
            $reasons[] = __('Popular choice', 'lol-ai-recommender');
        }
        
        $short_reason = implode('. ', array_slice($reasons, 0, 2));
        
        $thc = get_post_meta($product->ID, '_lol_thc', true);
        $cbd = get_post_meta($product->ID, '_lol_cbd', true);
        $image_url = get_post_meta($product->ID, '_lol_image_url', true);
        if (empty($image_url)) {
            $thumbnail_id = get_post_thumbnail_id($product->ID);
            if ($thumbnail_id) {
                $image_url = wp_get_attachment_image_url($thumbnail_id, 'medium');
            }
        }
        
        return array(
            'id' => $product->ID,
            'name' => $product->post_title,
            'price' => $price,
            'category' => $category,
            'brand' => $brand,
            'description' => wp_trim_words($description, 30),
            'effects' => $effects,
            'short_reason' => $short_reason,
            'remote_url' => $remote_url,
            'url' => $remote_url, // Alias for frontend compatibility
            'image' => $image_url,
            'thc' => $thc,
            'cbd' => $cbd,
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
    
    /**
     * Get available brands for question generation
     */
    public function get_available_brands($limit = 20) {
        $terms = get_terms(array(
            'taxonomy' => 'lol_brand',
            'hide_empty' => true,
            'number' => $limit,
        ));
        
        if (is_wp_error($terms)) {
            return array();
        }
        
        return wp_list_pluck($terms, 'name');
    }
}
