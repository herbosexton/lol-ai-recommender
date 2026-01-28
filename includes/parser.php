<?php
/**
 * Product Parser
 * Extracts product data from HTML using JSON-LD, OpenGraph, and embedded JSON
 */

if (!defined('ABSPATH')) {
    exit;
}

class LOL_Parser {
    
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
     * Parse product data from HTML
     */
    public function parse_product($html, $url) {
        $product_data = array(
            'remote_url' => $url,
            'name' => '',
            'brand' => '',
            'category' => '',
            'description' => '',
            'price' => '',
            'thc' => '',
            'cbd' => '',
            'tags' => array(),
            'effects' => array(),
            'flavors' => array(),
            'image_url' => '',
            'in_stock' => true,
            'remote_id' => '',
        );
        
        // Try JSON-LD first (most reliable)
        $json_ld = $this->extract_json_ld($html);
        if (!empty($json_ld)) {
            $product_data = array_merge($product_data, $this->parse_json_ld($json_ld));
        }
        
        // Fallback to OpenGraph
        $og_data = $this->extract_opengraph($html);
        if (!empty($og_data)) {
            $product_data = array_merge($product_data, $this->parse_opengraph($og_data));
        }
        
        // Try embedded JSON blobs
        $embedded_json = $this->extract_embedded_json($html);
        if (!empty($embedded_json)) {
            $product_data = array_merge($product_data, $this->parse_embedded_json($embedded_json));
        }
        
        // Extract category from page structure (breadcrumbs, navigation, etc.)
        if (empty($product_data['category'])) {
            $category_from_page = $this->extract_category_from_page($html, $url);
            if (!empty($category_from_page)) {
                $product_data['category'] = $category_from_page;
            }
        }
        
        // Enhance description with additional product details from page
        $enhanced_description = $this->extract_enhanced_description($html);
        if (!empty($enhanced_description) && empty($product_data['description'])) {
            $product_data['description'] = $enhanced_description;
        }
        
        // Extract remote_id from URL if possible
        $product_data['remote_id'] = $this->extract_id_from_url($url);
        
        // Sanitize all fields
        return $this->sanitize_product_data($product_data);
    }
    
    /**
     * Extract category from page structure (breadcrumbs, URL, navigation)
     */
    private function extract_category_from_page($html, $url) {
        $categories = array();
        
        // Extract from breadcrumbs
        preg_match_all('/<[^>]*class=["\'][^"\']*breadcrumb[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is', $html, $breadcrumb_matches);
        if (!empty($breadcrumb_matches[1])) {
            foreach ($breadcrumb_matches[1] as $breadcrumb) {
                preg_match_all('/<a[^>]*>([^<]+)<\/a>/i', $breadcrumb, $links);
                if (!empty($links[1])) {
                    foreach ($links[1] as $link_text) {
                        $link_text = trim(strip_tags($link_text));
                        // Skip common non-category terms
                        if (!in_array(strtolower($link_text), array('home', 'menu', 'products', 'shop', 'store'))) {
                            $categories[] = $link_text;
                        }
                    }
                }
            }
        }
        
        // Extract from URL path (e.g., /menu/flower/product-name)
        if (preg_match('/\/(?:menu|category|products?)\/([^\/]+)/i', $url, $url_matches)) {
            $url_category = urldecode($url_matches[1]);
            // Common category names
            $common_categories = array('flower', 'vapes', 'edibles', 'prerolls', 'concentrates', 'drinks', 'syrup', 'moon-rocks', 'tinctures', 'topicals', 'accessories', 'bundles', 'chocolates');
            if (in_array(strtolower($url_category), $common_categories) || strlen($url_category) > 3) {
                $categories[] = ucwords(str_replace('-', ' ', $url_category));
            }
        }
        
        // Extract from meta tags or structured data
        if (preg_match('/<meta[^>]*name=["\']category["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $meta_matches)) {
            $categories[] = $meta_matches[1];
        }
        
        // Extract from navigation/active menu items
        preg_match_all('/<[^>]*class=["\'][^"\']*(?:active|current|selected)[^"\']*["\'][^>]*>([^<]+)<\/[^>]+>/i', $html, $active_matches);
        if (!empty($active_matches[1])) {
            foreach ($active_matches[1] as $active_text) {
                $active_text = trim(strip_tags($active_text));
                if (strlen($active_text) > 2 && strlen($active_text) < 30) {
                    $categories[] = $active_text;
                }
            }
        }
        
        // Return first valid category found
        return !empty($categories) ? $categories[0] : '';
    }
    
    /**
     * Extract enhanced product description from page
     */
    private function extract_enhanced_description($html) {
        $description = '';
        
        // Try to find product description in common containers
        $description_patterns = array(
            '/<div[^>]*class=["\'][^"\']*description[^"\']*["\'][^>]*>(.*?)<\/div>/is',
            '/<p[^>]*class=["\'][^"\']*description[^"\']*["\'][^>]*>(.*?)<\/p>/is',
            '/<div[^>]*class=["\'][^"\']*product-description[^"\']*["\'][^>]*>(.*?)<\/div>/is',
            '/<div[^>]*class=["\'][^"\']*product-details[^"\']*["\'][^>]*>(.*?)<\/div>/is',
        );
        
        foreach ($description_patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $description = strip_tags($matches[1]);
                $description = preg_replace('/\s+/', ' ', $description);
                $description = trim($description);
                if (strlen($description) > 50) {
                    return $description;
                }
            }
        }
        
        // Try to extract from JSON-LD description (already handled, but check for longer version)
        if (preg_match('/"description"\s*:\s*"([^"]{50,})"/i', $html, $json_matches)) {
            return trim($json_matches[1]);
        }
        
        return '';
    }
    
    /**
     * Extract JSON-LD script tags
     */
    private function extract_json_ld($html) {
        $json_ld_data = array();
        
        // Match <script type="application/ld+json">...</script>
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $json_string) {
                $data = json_decode(trim($json_string), true);
                if ($data && isset($data['@type']) && $data['@type'] === 'Product') {
                    $json_ld_data[] = $data;
                }
            }
        }
        
        return $json_ld_data;
    }
    
    /**
     * Parse JSON-LD Product schema
     */
    private function parse_json_ld($json_ld_array) {
        $data = array();
        
        foreach ($json_ld_array as $json_ld) {
            // Name
            if (empty($data['name']) && isset($json_ld['name'])) {
                $data['name'] = $json_ld['name'];
            }
            
            // Brand
            if (empty($data['brand']) && isset($json_ld['brand'])) {
                if (is_array($json_ld['brand'])) {
                    $data['brand'] = isset($json_ld['brand']['name']) ? $json_ld['brand']['name'] : '';
                } else {
                    $data['brand'] = $json_ld['brand'];
                }
            }
            
            // Category
            if (empty($data['category']) && isset($json_ld['category'])) {
                $data['category'] = is_array($json_ld['category']) ? implode(', ', $json_ld['category']) : $json_ld['category'];
            }
            
            // Description
            if (empty($data['description']) && isset($json_ld['description'])) {
                $data['description'] = $json_ld['description'];
            }
            
            // Price
            if (empty($data['price']) && isset($json_ld['offers'])) {
                $offers = is_array($json_ld['offers']) && isset($json_ld['offers'][0]) ? $json_ld['offers'][0] : $json_ld['offers'];
                if (isset($offers['price'])) {
                    $data['price'] = $offers['price'];
                }
                if (isset($offers['availability']) && strpos(strtolower($offers['availability']), 'out') !== false) {
                    $data['in_stock'] = false;
                }
            }
            
            // Image
            if (empty($data['image_url']) && isset($json_ld['image'])) {
                if (is_array($json_ld['image'])) {
                    $data['image_url'] = isset($json_ld['image'][0]) ? $json_ld['image'][0] : (isset($json_ld['image']['url']) ? $json_ld['image']['url'] : '');
                } else {
                    $data['image_url'] = $json_ld['image'];
                }
            }
            
            // Additional properties (THC, CBD, etc.)
            if (isset($json_ld['additionalProperty'])) {
                foreach ($json_ld['additionalProperty'] as $prop) {
                    if (isset($prop['name']) && isset($prop['value'])) {
                        $name = strtolower($prop['name']);
                        if ($name === 'thc' || $name === 'thc%') {
                            $data['thc'] = $prop['value'];
                        } elseif ($name === 'cbd' || $name === 'cbd%') {
                            $data['cbd'] = $prop['value'];
                        } elseif (strpos($name, 'effect') !== false) {
                            $data['effects'][] = $prop['value'];
                        } elseif (strpos($name, 'flavor') !== false || strpos($name, 'taste') !== false) {
                            $data['flavors'][] = $prop['value'];
                        }
                    }
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Extract OpenGraph meta tags
     */
    private function extract_opengraph($html) {
        $og_data = array();
        
        preg_match_all('/<meta[^>]*property=["\']og:([^"\']+)["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $index => $property) {
                $og_data[$property] = $matches[2][$index];
            }
        }
        
        // Also try name attribute
        preg_match_all('/<meta[^>]*name=["\']og:([^"\']+)["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $index => $property) {
                if (!isset($og_data[$property])) {
                    $og_data[$property] = $matches[2][$index];
                }
            }
        }
        
        return $og_data;
    }
    
    /**
     * Parse OpenGraph data
     */
    private function parse_opengraph($og_data) {
        $data = array();
        
        if (isset($og_data['title']) && empty($data['name'])) {
            $data['name'] = $og_data['title'];
        }
        
        if (isset($og_data['description']) && empty($data['description'])) {
            $data['description'] = $og_data['description'];
        }
        
        if (isset($og_data['image']) && empty($data['image_url'])) {
            $data['image_url'] = $og_data['image'];
        }
        
        // Try to extract price from og:price:amount
        if (isset($og_data['price:amount']) && empty($data['price'])) {
            $data['price'] = $og_data['price:amount'];
        }
        
        return $data;
    }
    
    /**
     * Extract embedded JSON blobs (common in React/SPA apps)
     */
    private function extract_embedded_json($html) {
        $json_data = array();
        
        // Look for common patterns like window.__INITIAL_STATE__, __NEXT_DATA__, etc.
        $patterns = array(
            '/window\.__INITIAL_STATE__\s*=\s*({.+?});/is',
            '/__NEXT_DATA__\s*=\s*({.+?})<\/script>/is',
            '/window\.__PRELOADED_STATE__\s*=\s*({.+?});/is',
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $json = json_decode($matches[1], true);
                if ($json) {
                    $json_data[] = $json;
                }
            }
        }
        
        // Also look for data attributes with JSON
        preg_match_all('/data-[^=]*=["\']({.+?})["\']/i', $html, $matches);
        foreach ($matches[1] as $json_string) {
            $json = json_decode($json_string, true);
            if ($json) {
                $json_data[] = $json;
            }
        }
        
        return $json_data;
    }
    
    /**
     * Parse embedded JSON (heuristic approach)
     */
    private function parse_embedded_json($json_array) {
        $data = array();
        
        foreach ($json_array as $json) {
            // Recursively search for product-like structures
            $product_info = $this->find_product_in_json($json);
            if (!empty($product_info)) {
                $data = array_merge($data, $product_info);
            }
        }
        
        return $data;
    }
    
    /**
     * Recursively find product data in JSON structure
     */
    private function find_product_in_json($data, $depth = 0) {
        if ($depth > 10) { // Prevent infinite recursion
            return array();
        }
        
        $product_data = array();
        
        if (!is_array($data)) {
            return $product_data;
        }
        
        // Look for common product keys
        $product_keys = array('name', 'title', 'productName', 'brand', 'category', 'description', 'price', 'thc', 'cbd', 'image', 'imageUrl', 'inStock', 'in_stock');
        
        foreach ($product_keys as $key) {
            if (isset($data[$key])) {
                $value = $data[$key];
                
                switch ($key) {
                    case 'name':
                    case 'title':
                    case 'productName':
                        if (empty($product_data['name'])) {
                            $product_data['name'] = $value;
                        }
                        break;
                    case 'brand':
                        if (empty($product_data['brand'])) {
                            $product_data['brand'] = $value;
                        }
                        break;
                    case 'category':
                        if (empty($product_data['category'])) {
                            $product_data['category'] = is_array($value) ? implode(', ', $value) : $value;
                        }
                        break;
                    case 'description':
                        if (empty($product_data['description'])) {
                            $product_data['description'] = $value;
                        }
                        break;
                    case 'price':
                        if (empty($product_data['price'])) {
                            $product_data['price'] = $value;
                        }
                        break;
                    case 'thc':
                        if (empty($product_data['thc'])) {
                            $product_data['thc'] = $value;
                        }
                        break;
                    case 'cbd':
                        if (empty($product_data['cbd'])) {
                            $product_data['cbd'] = $value;
                        }
                        break;
                    case 'image':
                    case 'imageUrl':
                        if (empty($product_data['image_url'])) {
                            $product_data['image_url'] = is_array($value) ? (isset($value[0]) ? $value[0] : '') : $value;
                        }
                        break;
                    case 'inStock':
                    case 'in_stock':
                        $product_data['in_stock'] = (bool) $value;
                        break;
                }
            }
        }
        
        // Recursively search nested arrays
        foreach ($data as $value) {
            if (is_array($value)) {
                $nested = $this->find_product_in_json($value, $depth + 1);
                $product_data = array_merge($product_data, $nested);
            }
        }
        
        return $product_data;
    }
    
    /**
     * Extract ID from URL
     */
    private function extract_id_from_url($url) {
        // Try to extract ID from URL patterns
        if (preg_match('/\/([a-f0-9]{24})\/?$/i', $url, $matches)) { // MongoDB-style ID
            return $matches[1];
        }
        if (preg_match('/\/(\d+)\/?$/', $url, $matches)) { // Numeric ID
            return $matches[1];
        }
        if (preg_match('/[?&]id=([^&]+)/', $url, $matches)) { // Query param
            return $matches[1];
        }
        
        return '';
    }
    
    /**
     * Sanitize product data
     */
    private function sanitize_product_data($data) {
        $sanitized = array();
        
        $sanitized['remote_url'] = esc_url_raw($data['remote_url']);
        $sanitized['name'] = sanitize_text_field($data['name']);
        $sanitized['brand'] = sanitize_text_field($data['brand']);
        $sanitized['category'] = sanitize_text_field($data['category']);
        $sanitized['description'] = wp_kses_post($data['description']);
        $sanitized['price'] = sanitize_text_field($data['price']);
        $sanitized['thc'] = sanitize_text_field($data['thc']);
        $sanitized['cbd'] = sanitize_text_field($data['cbd']);
        $sanitized['image_url'] = esc_url_raw($data['image_url']);
        $sanitized['in_stock'] = (bool) $data['in_stock'];
        $sanitized['remote_id'] = sanitize_text_field($data['remote_id']);
        
        // Arrays
        $sanitized['tags'] = array_map('sanitize_text_field', (array) $data['tags']);
        $sanitized['effects'] = array_map('sanitize_text_field', (array) $data['effects']);
        $sanitized['flavors'] = array_map('sanitize_text_field', (array) $data['flavors']);
        
        return $sanitized;
    }
}
