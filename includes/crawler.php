<?php
/**
 * Sitemap Crawler and Direct Website Scraper
 */

if (!defined('ABSPATH')) {
    exit;
}

class LOL_Crawler {
    
    private static $instance = null;
    private $rate_limit = 30; // requests per minute
    private $last_request_time = 0;
    private $request_count = 0;
    private $minute_start = 0;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->rate_limit = get_option('lol_crawl_rate_limit', 30);
    }
    
    /**
     * Fetch product URLs - tries sitemap first, then direct scraping
     */
    public function fetch_product_urls($sitemap_url = '', $menu_base_url = '') {
        $product_urls = array();
        
        // Try sitemap first if provided
        if (!empty($sitemap_url)) {
            $sitemap_urls = $this->fetch_sitemap_urls($sitemap_url);
            if (!is_wp_error($sitemap_urls) && !empty($sitemap_urls)) {
                return $sitemap_urls;
            }
        }
        
        // Fallback to direct website scraping
        if (!empty($menu_base_url)) {
            $scraped_urls = $this->scrape_product_urls($menu_base_url);
            if (!is_wp_error($scraped_urls) && !empty($scraped_urls)) {
                return $scraped_urls;
            }
        }
        
        // If both failed, return error
        if (empty($product_urls)) {
            return new WP_Error('no_urls_found', __('Could not find product URLs from sitemap or website scraping', 'lol-ai-recommender'));
        }
        
        return $product_urls;
    }
    
    /**
     * Fetch sitemap and extract product URLs
     */
    public function fetch_sitemap_urls($sitemap_url) {
        // Check cache
        $cache_key = 'lol_sitemap_' . md5($sitemap_url);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Fetch sitemap
        $response = $this->make_request($sitemap_url);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('sitemap_not_found', sprintf(__('Sitemap returned status code: %d', 'lol-ai-recommender'), $code));
        }
        
        $body = wp_remote_retrieve_body($response);
        $urls = $this->parse_sitemap($body, $sitemap_url);
        
        // Cache for 1 hour
        set_transient($cache_key, $urls, HOUR_IN_SECONDS);
        
        return $urls;
    }
    
    /**
     * Scrape product URLs directly from the website
     */
    public function scrape_product_urls($menu_base_url, $max_pages = 20) {
        // Check cache
        $cache_key = 'lol_scraped_' . md5($menu_base_url);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $product_urls = array();
        $visited_urls = array();
        $urls_to_visit = array($menu_base_url);
        
        $parsed_base = parse_url($menu_base_url);
        $base_host = $parsed_base['scheme'] . '://' . $parsed_base['host'];
        
        while (!empty($urls_to_visit) && count($visited_urls) < $max_pages) {
            $current_url = array_shift($urls_to_visit);
            
            // Skip if already visited
            if (isset($visited_urls[$current_url])) {
                continue;
            }
            
            $visited_urls[$current_url] = true;
            
            // Fetch page
            $response = $this->make_request($current_url);
            
            if (is_wp_error($response)) {
                continue;
            }
            
            $body = wp_remote_retrieve_body($response);
            
            // Extract product URLs from the page
            $found_products = $this->extract_product_urls_from_html($body, $base_host);
            
            foreach ($found_products as $product_url) {
                if (!isset($product_urls[$product_url])) {
                    $product_urls[$product_url] = true;
                }
            }
            
            // Extract category/menu page links to crawl further
            $category_links = $this->extract_category_links($body, $base_host, $menu_base_url);
            foreach ($category_links as $link) {
                if (!isset($visited_urls[$link]) && !in_array($link, $urls_to_visit)) {
                    $urls_to_visit[] = $link;
                }
            }
            
            // Rate limiting delay
            usleep(500000); // 0.5 seconds between pages
        }
        
        $product_urls = array_keys($product_urls);
        
        // Cache for 2 hours
        set_transient($cache_key, $product_urls, HOUR_IN_SECONDS * 2);
        
        return $product_urls;
    }
    
    /**
     * Extract product URLs from HTML content
     */
    private function extract_product_urls_from_html($html, $base_host) {
        $product_urls = array();
        
        // Common patterns for product links in Dutchie/Blaze menus
        // Look for links that contain product identifiers
        
        // Pattern 1: Links with /product/ or /products/ in path
        preg_match_all('/href=["\']([^"\']*(?:\/product|\/products|\/item)[^"\']*)["\']/i', $html, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $url = $this->normalize_url($url, $base_host);
                if ($url && $this->is_product_url($url, '')) {
                    $product_urls[] = $url;
                }
            }
        }
        
        // Pattern 2: Data attributes (common in React/SPA apps)
        preg_match_all('/data-[^=]*url=["\']([^"\']+)["\']/i', $html, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $url = $this->normalize_url($url, $base_host);
                if ($url && $this->is_product_url($url, '')) {
                    $product_urls[] = $url;
                }
            }
        }
        
        // Pattern 3: JSON-LD Product schema URLs
        preg_match_all('/"@type"\s*:\s*"Product"[^}]*"url"\s*:\s*"([^"]+)"/i', $html, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $url = $this->normalize_url($url, $base_host);
                if ($url) {
                    $product_urls[] = $url;
                }
            }
        }
        
        // Pattern 4: Look for product cards/items with specific classes (Dutchie/Blaze specific)
        // Many Dutchie sites use data-product-id or similar attributes
        preg_match_all('/<a[^>]*(?:data-product|class="[^"]*product[^"]*")[^>]*href=["\']([^"\']+)["\']/i', $html, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $url = $this->normalize_url($url, $base_host);
                if ($url) {
                    $product_urls[] = $url;
                }
            }
        }
        
        return array_unique($product_urls);
    }
    
    /**
     * Extract category/menu page links for further crawling
     */
    private function extract_category_links($html, $base_host, $menu_base) {
        $links = array();
        
        // Look for category links (usually in navigation or filters)
        // Common patterns: /menu/category-name, /category/, etc.
        preg_match_all('/href=["\']([^"\']*(?:\/menu\/|\/category\/|\/pickup\/|\/delivery\/)[^"\']*)["\']/i', $html, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $url = $this->normalize_url($url, $base_host);
                // Only include if it's under the menu base
                if ($url && strpos($url, $menu_base) === 0 && !$this->is_product_url($url, '')) {
                    $links[] = $url;
                }
            }
        }
        
        return array_unique($links);
    }
    
    /**
     * Normalize URL (make absolute, remove fragments, etc.)
     */
    private function normalize_url($url, $base_host) {
        // Remove fragments
        $url = strtok($url, '#');
        
        // Skip javascript:, mailto:, etc.
        if (preg_match('/^(javascript|mailto|tel|#)/i', $url)) {
            return '';
        }
        
        // If relative URL, make it absolute
        if (strpos($url, 'http') !== 0) {
            if (strpos($url, '/') === 0) {
                // Absolute path
                $url = $base_host . $url;
            } else {
                // Relative path
                $url = $base_host . '/' . $url;
            }
        }
        
        // Remove query parameters that might cause duplicates
        $url = strtok($url, '?');
        
        return $url;
    }
    
    /**
     * Parse sitemap XML
     */
    private function parse_sitemap($xml_content, $base_url) {
        $urls = array();
        
        // Handle sitemap index (contains multiple sitemaps)
        if (strpos($xml_content, '<sitemapindex') !== false) {
            $sitemap_urls = $this->extract_sitemap_urls($xml_content);
            foreach ($sitemap_urls as $sitemap_url) {
                $response = $this->make_request($sitemap_url);
                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    $urls = array_merge($urls, $this->extract_urls_from_sitemap($body));
                }
            }
        } else {
            $urls = $this->extract_urls_from_sitemap($xml_content);
        }
        
        // Filter product URLs (heuristic: contains /product/ or /menu/ or similar)
        $product_urls = array();
        $menu_base = get_option('lol_dutchie_menu_base_url', '');
        
        foreach ($urls as $url) {
            // Check if URL looks like a product page
            if ($this->is_product_url($url, $menu_base)) {
                $product_urls[] = $url;
            }
        }
        
        return array_unique($product_urls);
    }
    
    /**
     * Extract URLs from sitemap XML
     */
    private function extract_urls_from_sitemap($xml_content) {
        $urls = array();
        
        // Simple regex extraction (more robust would use SimpleXML)
        preg_match_all('/<loc>(.*?)<\/loc>/i', $xml_content, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $url = trim($url);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $urls[] = $url;
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * Extract sitemap URLs from sitemap index
     */
    private function extract_sitemap_urls($xml_content) {
        $urls = array();
        preg_match_all('/<loc>(.*?)<\/loc>/i', $xml_content, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $url = trim($url);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $urls[] = $url;
                }
            }
        }
        
        return $urls;
    }
    
    /**
     * Check if URL is a product URL
     */
    private function is_product_url($url, $menu_base) {
        // Common patterns for product pages
        $patterns = array(
            '/\/product\//',
            '/\/products\//',
            '/\/menu\/.*\/[^\/]+$/', // menu/category/product-name
            '/\/item\//',
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        // If menu base is set, ensure URL is under it
        if ($menu_base && strpos($url, $menu_base) === 0) {
            // Check it's not a category/index page
            if (preg_match('/\/[^\/]+\/[^\/]+$/', $url)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Fetch product page content
     */
    public function fetch_product_page($url) {
        // Check ETag/Last-Modified cache
        $cache_key = 'lol_product_' . md5($url);
        $cached_etag = get_transient($cache_key . '_etag');
        $cached_lastmod = get_transient($cache_key . '_lastmod');
        
        $headers = array(
            'User-Agent' => 'LOL-AI-Recommender/1.0 (WordPress Plugin; +https://example.com)',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        );
        
        // Add conditional headers if cached
        if ($cached_etag) {
            $headers['If-None-Match'] = $cached_etag;
        }
        if ($cached_lastmod) {
            $headers['If-Modified-Since'] = $cached_lastmod;
        }
        
        $response = $this->make_request($url, array('headers' => $headers));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        // 304 Not Modified - use cached content
        if ($code === 304) {
            $cached_content = get_transient($cache_key . '_content');
            if ($cached_content !== false) {
                return array(
                    'body' => $cached_content,
                    'etag' => $cached_etag,
                    'last_modified' => $cached_lastmod,
                );
            }
        }
        
        // Store ETag and Last-Modified if present
        $etag = wp_remote_retrieve_header($response, 'etag');
        $last_modified = wp_remote_retrieve_header($response, 'last-modified');
        
        if ($etag) {
            set_transient($cache_key . '_etag', $etag, DAY_IN_SECONDS * 7);
        }
        if ($last_modified) {
            set_transient($cache_key . '_lastmod', $last_modified, DAY_IN_SECONDS * 7);
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Cache content for 1 hour
        set_transient($cache_key . '_content', $body, HOUR_IN_SECONDS);
        
        return array(
            'body' => $body,
            'etag' => $etag,
            'last_modified' => $last_modified,
        );
    }
    
    /**
     * Make HTTP request with rate limiting
     */
    private function make_request($url, $args = array()) {
        // Rate limiting
        $current_time = time();
        
        // Reset counter if new minute
        if ($current_time - $this->minute_start >= 60) {
            $this->request_count = 0;
            $this->minute_start = $current_time;
        }
        
        // Wait if rate limit exceeded
        if ($this->request_count >= $this->rate_limit) {
            $wait_time = 60 - ($current_time - $this->minute_start);
            if ($wait_time > 0) {
                sleep($wait_time);
                $this->request_count = 0;
                $this->minute_start = time();
            }
        }
        
        // Ensure minimum delay between requests
        $time_since_last = $current_time - $this->last_request_time;
        $min_delay = 60 / $this->rate_limit; // seconds between requests
        if ($time_since_last < $min_delay) {
            usleep(($min_delay - $time_since_last) * 1000000);
        }
        
        $this->last_request_time = time();
        $this->request_count++;
        
        // Default args
        $defaults = array(
            'timeout' => 30,
            'sslverify' => true,
            'headers' => array(
                'User-Agent' => 'LOL-AI-Recommender/1.0 (WordPress Plugin)',
            ),
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Check robots.txt (simple check)
        $this->check_robots_txt($url);
        
        return wp_remote_get($url, $args);
    }
    
    /**
     * Check robots.txt (basic implementation)
     */
    private function check_robots_txt($url) {
        $parsed = parse_url($url);
        $robots_url = $parsed['scheme'] . '://' . $parsed['host'] . '/robots.txt';
        
        $cache_key = 'lol_robots_' . md5($parsed['host']);
        $robots_content = get_transient($cache_key);
        
        if ($robots_content === false) {
            $response = wp_remote_get($robots_url, array('timeout' => 10));
            if (!is_wp_error($response)) {
                $robots_content = wp_remote_retrieve_body($response);
                set_transient($cache_key, $robots_content, DAY_IN_SECONDS);
            } else {
                $robots_content = '';
            }
        }
        
        // Basic robots.txt check (respect Crawl-Delay if present)
        if ($robots_content && preg_match('/Crawl-Delay:\s*(\d+)/i', $robots_content, $matches)) {
            $delay = intval($matches[1]);
            if ($delay > 0) {
                sleep($delay);
            }
        }
    }
}
