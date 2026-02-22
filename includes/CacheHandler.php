<?php

/**
 * Cache Handler Class for Samrat Website Cache
 *
 * @package Samrat_Website_Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

class CacheHandler {

    /**
     * Cache settings
     *
     * @var array
     */
    private $settings;

    /**
     * Whether we should attempt caching
     *
     * @var bool
     */
    private $can_cache = false;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = $this->get_settings();
        
        // Only add cache hooks if page cache is enabled
        if ($this->settings['enable_page_cache']) {
            // Try to serve cached content early (before WordPress loads)
            $this->maybe_serve_cached_content();
            
            // Start output buffering after template is loaded
            add_action('template_redirect', array($this, 'start_cache'), 0);
        }

        // Auto-clear cache hooks
        add_action('save_post', array($this, 'clear_cache_on_update'));
        add_action('deleted_post', array($this, 'clear_cache_on_update'));
        add_action('switch_theme', array($this, 'clear_all_cache'));
        add_action('activated_plugin', array($this, 'clear_all_cache'));
        add_action('deactivated_plugin', array($this, 'clear_all_cache'));
        add_action('upgrader_process_complete', array($this, 'clear_all_cache'));
        
        // WooCommerce specific hooks — these pass WC_Product objects, so use a dedicated handler.
        add_action('woocommerce_product_set_stock', array($this, 'clear_cache_on_wc_stock_update'));
        add_action('woocommerce_variation_set_stock', array($this, 'clear_cache_on_wc_stock_update'));
    }

    /**
     * Get cache settings
     *
     * @return array
     */
    private function get_settings() {
        $defaults = array(
            'enable_page_cache' => 1,
            'cache_logged_users' => 0,
            'cache_expiry' => 86400,
            'minify_html' => 0,
            'minify_css' => 0,
            'minify_js' => 0,
            'exclude_pages' => "/cart/\n/checkout/\n/my-account/",
            'exclude_cookies' => "woocommerce_cart_hash\nwoocommerce_items_in_cart",
        );

        $options = get_option('samrat_website_cache_settings', $defaults);
        return wp_parse_args($options, $defaults);
    }

    /**
     * Check basic conditions (before WordPress is fully loaded)
     *
     * @return bool
     */
    private function can_serve_cache_early() {
        // Don't cache admin pages
        if (is_admin()) {
            return false;
        }

        // Don't cache AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return false;
        }

        // Don't cache cron
        if (defined('DOING_CRON') && DOING_CRON) {
            return false;
        }

        // Don't cache WP CLI
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }

        // Don't cache POST requests
        $request_method = '';
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $request_method = sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']));
        }

        if ($request_method !== 'GET') {
            return false;
        }

        // Check excluded pages (simple URL check)
        if ($this->is_excluded_page()) {
            return false;
        }

        // Check excluded cookies
        if ($this->has_excluded_cookie()) {
            return false;
        }

        // Don't serve cache if logged in (check cookie)
        if (!$this->settings['cache_logged_users']) {
            $cookies = wp_unslash($_COOKIE);
            foreach ($cookies as $key => $value) {
                if (strpos($key, 'wordpress_logged_in_') === 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if current page should be cached (full check after WP loads)
     *
     * @return bool
     */
    private function should_cache() {
        // Don't cache admin pages
        if (is_admin()) {
            return false;
        }

        // Don't cache AJAX requests
        if (wp_doing_ajax()) {
            return false;
        }

        // Don't cache REST API requests
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        // Don't cache POST requests
        $request_method = '';
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $request_method = sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']));
        }

        if ($request_method !== 'GET') {
            return false;
        }

        // Check logged-in user setting
        if (is_user_logged_in() && !$this->settings['cache_logged_users']) {
            return false;
        }

        // Check excluded pages
        if ($this->is_excluded_page()) {
            return false;
        }

        // Check excluded cookies
        if ($this->has_excluded_cookie()) {
            return false;
        }

        // Don't cache search results
        if (function_exists('is_search') && is_search()) {
            return false;
        }

        // Don't cache 404 pages
        if (function_exists('is_404') && is_404()) {
            return false;
        }

        // Don't cache feed pages
        if (function_exists('is_feed') && is_feed()) {
            return false;
        }

        // Don't cache preview pages
        if (function_exists('is_preview') && is_preview()) {
            return false;
        }

        // Don't cache password protected posts
        if (function_exists('post_password_required') && post_password_required()) {
            return false;
        }

        return true;
    }

    /**
     * Check if current page is in excluded pages list
     *
     * @return bool
     */
    private function is_excluded_page() {
        $excluded_pages = $this->settings['exclude_pages'];
        if (empty($excluded_pages)) {
            return false;
        }

        $excluded = array_filter(array_map('trim', explode("\n", $excluded_pages)));
        $current_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        foreach ($excluded as $pattern) {
            if (empty($pattern)) {
                continue;
            }
            
            // Check if pattern contains wildcard
            if (strpos($pattern, '*') !== false) {
                $regex = '#' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '#';
                if (preg_match($regex, $current_uri)) {
                    return true;
                }
            } else {
                if (strpos($current_uri, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if any excluded cookie is present
     *
     * @return bool
     */
    private function has_excluded_cookie() {
        $excluded_cookies = $this->settings['exclude_cookies'];
        if (empty($excluded_cookies)) {
            return false;
        }

        $excluded = array_filter(array_map('trim', explode("\n", $excluded_cookies)));
        $cookies  = wp_unslash($_COOKIE);

        foreach ($excluded as $cookie_name) {
            if (isset($cookies[$cookie_name]) && !empty($cookies[$cookie_name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Try to serve cached content before WordPress fully loads
     */
    private function maybe_serve_cached_content() {
        if (!$this->can_serve_cache_early()) {
            return;
        }

        $cache_file = $this->get_cache_file();

        if (!file_exists($cache_file)) {
            return;
        }

        // Check cache expiry
        $file_time = filemtime($cache_file);
        $expiry = intval($this->settings['cache_expiry']);

        if ((time() - $file_time) > $expiry) {
            // Cache expired, delete it
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink($cache_file);
            return;
        }

        // Serve cached content
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = @file_get_contents($cache_file);
        
        if (empty($content)) {
            return;
        }

        // Set appropriate headers
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Samrat-Cache: HIT');
        header('X-Samrat-Cache-Time: ' . gmdate('Y-m-d H:i:s', $file_time));
        
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $content;
        exit;
    }

    /**
     * Start output buffering for cache
     */
    public function start_cache() {
        // Final check if we should cache this page
        if (!$this->should_cache()) {
            return;
        }

        $this->can_cache = true;

        // Start output buffering - explicitly closed in end_cache() via shutdown hook
        ob_start();
        add_action('shutdown', array($this, 'end_cache'), 0);
    }

    /**
     * End output buffering, process and cache the captured content
     *
     * Explicitly closes the buffer opened in start_cache() so the buffer
     * is always paired with a closing call within a traceable code path.
     */
    public function end_cache() {
        if (!$this->can_cache || ob_get_level() === 0) {
            return;
        }

        $content = ob_get_clean();

        // Process and save to cache
        $this->process_and_cache($content);

        // Output original content to browser
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $content;
    }

    /**
     * Process buffered output and save to cache file
     *
     * @param string $content The buffered output
     */
    private function process_and_cache($content) {
        // Don't cache empty content
        if (empty($content)) {
            return;
        }

        // Don't cache if it doesn't look like a complete HTML page
        if (strpos($content, '</html>') === false && strpos($content, '</HTML>') === false) {
            return;
        }

        // Don't cache error pages
        if (http_response_code() !== 200) {
            return;
        }

        // Apply minification if enabled
        $cached_content = $this->maybe_minify($content);

        // Add cache signature
        $cached_content .= $this->get_cache_signature();

        // Save the cache content to a file
        $this->save_cache($cached_content);
    }

    /**
     * Save content to cache file
     *
     * @param string $content Content to cache
     */
    private function save_cache($content) {
        $cache_file = $this->get_cache_file();
        $cache_dir = dirname($cache_file);

        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents($cache_file, $content, LOCK_EX);
    }

    /**
     * Apply minification to content
     *
     * @param string $content HTML content
     * @return string Minified content
     */
    private function maybe_minify($content) {
        if ($this->settings['minify_html']) {
            $content = $this->minify_html($content);
        }

        if ($this->settings['minify_css']) {
            $content = $this->minify_inline_css($content);
        }

        if ($this->settings['minify_js']) {
            $content = $this->minify_inline_js($content);
        }

        return $content;
    }

    /**
     * Minify HTML content
     *
     * @param string $html HTML content
     * @return string Minified HTML
     */
    private function minify_html($html) {
        // Don't minify if no HTML
        if (empty($html)) {
            return $html;
        }

        // Store pre/code/textarea/script/style content
        $protected = array();
        $html = preg_replace_callback(
            '#(<(?:pre|code|textarea|script|style)[^>]*>)(.*?)(</(?:pre|code|textarea|script|style)>)#si',
            function ($matches) use (&$protected) {
                $key = '<!-- SAMRAT_PROTECTED_' . count($protected) . ' -->';
                $protected[$key] = $matches[0];
                return $key;
            },
            $html
        );

        // Remove HTML comments (except IE conditionals and protected)
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>|SAMRAT_PROTECTED))(?:(?!-->).)*-->/s', '', $html);
        
        // Remove whitespace between tags (be careful with inline elements)
        $html = preg_replace('/>\s+</', '> <', $html);
        
        // Remove multiple spaces (but keep at least one)
        $html = preg_replace('/\s{2,}/', ' ', $html);
        
        // Remove unnecessary whitespace around block elements
        $html = preg_replace('/\s*(<\/?(?:div|p|section|article|header|footer|nav|aside|main|ul|ol|li|h[1-6]|table|tr|td|th|thead|tbody|form)[^>]*>)\s*/i', '$1', $html);

        // Restore protected content
        $html = str_replace(array_keys($protected), array_values($protected), $html);

        return trim($html);
    }

    /**
     * Minify inline CSS
     *
     * @param string $html HTML content
     * @return string HTML with minified CSS
     */
    private function minify_inline_css($html) {
        return preg_replace_callback(
            '#<style[^>]*>(.*?)</style>#si',
            function ($matches) {
                $css = $matches[1];
                
                // Remove comments
                $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
                
                // Remove whitespace
                $css = preg_replace('/\s+/', ' ', $css);
                
                // Remove spaces around special characters
                $css = preg_replace('/\s*([:;{},>+])\s*/', '$1', $css);
                
                // Remove trailing semicolons before closing braces
                $css = preg_replace('/;}/', '}', $css);
                
                // Get opening tag
                preg_match('#<style[^>]*>#i', $matches[0], $tag);
                
                return $tag[0] . trim($css) . '</style>';
            },
            $html
        );
    }

    /**
     * Minify inline JavaScript
     *
     * @param string $html HTML content
     * @return string HTML with minified JS
     */
    private function minify_inline_js($html) {
        return preg_replace_callback(
            '#<script([^>]*)>(.*?)</script>#si',
            function ($matches) {
                $attrs = $matches[1];
                $js = $matches[2];
                
                // Skip if has src attribute (external script) or empty
                if (preg_match('/\bsrc\s*=/i', $attrs) || empty(trim($js))) {
                    return $matches[0];
                }

                // Skip JSON scripts
                if (preg_match('/type\s*=\s*["\']application\/(?:ld\+)?json["\']/i', $attrs)) {
                    return $matches[0];
                }
                
                // Remove single-line comments (but not URLs)
                $js = preg_replace('#(?<![:\'"=])//(?![\'"]).*$#m', '', $js);
                
                // Remove multi-line comments
                $js = preg_replace('#/\*.*?\*/#s', '', $js);
                
                // Remove excessive whitespace (but be careful with strings)
                $js = preg_replace('/\s+/', ' ', $js);
                
                return '<script' . $attrs . '>' . trim($js) . '</script>';
            },
            $html
        );
    }

    /**
     * Get cache signature comment
     *
     * @return string
     */
    private function get_cache_signature() {
        return "\n<!-- Cached by Samrat Website Cache on " . gmdate('Y-m-d H:i:s') . " -->";
    }

    /**
     * Get cache file path
     *
     * @return string
     */
    private function get_cache_file() {
        $cache_dir = SAMRAT_WEBSITE_CACHE_DIR;
        
        // Create a unique cache key based on URL and user state
        $cache_key = $this->get_cache_key();
        
        return $cache_dir . $cache_key . '.html';
    }

    /**
     * Get cache key for current request
     *
     * @return string
     */
    private function get_cache_key() {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

        // Use the configured site host rather than the user-supplied HTTP_HOST header.
        // HTTP_HOST is fully attacker-controlled and using it directly would allow
        // cache pollution via forged Host headers (disk exhaustion attack).
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        $key_parts = array($host, $uri);
        
        // Include user state in key if caching for logged-in users
        if ($this->settings['cache_logged_users']) {
            $cookies = wp_unslash($_COOKIE);
            foreach ($cookies as $key => $value) {
                if (strpos($key, 'wordpress_logged_in_') === 0) {
                    $key_parts[] = 'logged_in';
                    break;
                }
            }
        }
        
        return md5(implode('|', $key_parts));
    }

    /**
     * Clear cache when WooCommerce stock changes.
     *
     * woocommerce_product_set_stock and woocommerce_variation_set_stock both pass
     * a WC_Product (or WC_Product_Variation) object rather than a plain integer ID.
     *
     * @param object $product WC_Product or WC_Product_Variation instance.
     */
    public function clear_cache_on_wc_stock_update($product) {
        if (is_a($product, 'WC_Product')) {
            $this->clear_cache_on_update($product->get_id());
        }
    }

    /**
     * Clear cache when content is updated
     *
     * @param int $post_id Post ID
     */
    public function clear_cache_on_update($post_id = null) {
        // Don't run on autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Clear specific post cache if possible
        if ($post_id) {
            $post = get_post($post_id);
            
            // Skip revisions and auto-drafts
            if (!$post || $post->post_status === 'auto-draft' || $post->post_type === 'revision') {
                return;
            }

            $permalink = get_permalink($post_id);
            if ($permalink) {
                $parsed = wp_parse_url($permalink);
                $uri = isset($parsed['path']) ? $parsed['path'] : '/';
                $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                $host = isset($parsed['host']) ? $parsed['host'] : wp_parse_url(home_url(), PHP_URL_HOST);

                // Normalise with esc_url_raw() so the key matches what get_cache_key() produces.
                $normalized_uri = esc_url_raw($uri . $query);

                // Clear non-logged-in cache
                $cache_key = md5($host . '|' . $normalized_uri);
                $cache_file = SAMRAT_WEBSITE_CACHE_DIR . $cache_key . '.html';

                if (file_exists($cache_file)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink($cache_file);
                }

                // Clear logged-in user cache variant if that feature is enabled
                if ($this->settings['cache_logged_users']) {
                    $logged_in_key  = md5($host . '|' . $normalized_uri . '|logged_in');
                    $logged_in_file = SAMRAT_WEBSITE_CACHE_DIR . $logged_in_key . '.html';
                    if (file_exists($logged_in_file)) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                        @unlink($logged_in_file);
                    }
                }
            }
        }

        // Also clear homepage cache
        $home_url = home_url('/');
        $parsed = wp_parse_url($home_url);
        $host = isset($parsed['host']) ? $parsed['host'] : '';

        $home_cache_key = md5($host . '|/');
        $home_cache_file = SAMRAT_WEBSITE_CACHE_DIR . $home_cache_key . '.html';

        if (file_exists($home_cache_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink($home_cache_file);
        }

        // Clear logged-in homepage cache variant if that feature is enabled
        if ($this->settings['cache_logged_users']) {
            $home_logged_in_key  = md5($host . '|/|logged_in');
            $home_logged_in_file = SAMRAT_WEBSITE_CACHE_DIR . $home_logged_in_key . '.html';
            if (file_exists($home_logged_in_file)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                @unlink($home_logged_in_file);
            }
        }
    }

    /**
     * Clear all cache files
     */
    public function clear_all_cache() {
        $cache_dir = SAMRAT_WEBSITE_CACHE_DIR;
        
        if (!file_exists($cache_dir)) {
            return;
        }

        $files = glob($cache_dir . '*.html');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink($file);
                }
            }
        }
    }
}
