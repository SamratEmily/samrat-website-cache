<?php

/**
 * Plugin name: Samrat Website Cache
 * Description: A powerful caching plugin for WordPress to improve website performance by caching pages, minifying HTML/CSS/JS, and more.
 * Version: 1.0.0
 * Author: Samrat Hossen
 * Author URI: https://samrat-personal-portfolio.netlify.app/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: samrat-website-cache
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define Constants
define('SAMRAT_WEBSITE_CACHE_VERSION', '1.0.0');
define('SAMRAT_WEBSITE_CACHE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAMRAT_WEBSITE_CACHE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SAMRAT_WEBSITE_CACHE_PLUGIN_FILE', __FILE__);

/**
 * Initialize the plugin
 */
function samrat_website_cache_init() {
    // Load Cache Handler
    require_once SAMRAT_WEBSITE_CACHE_PLUGIN_DIR . 'includes/CacheHandler.php';
    new CacheHandler();

    // Load Admin Menu (only in admin area)
    if (is_admin()) {
        require_once SAMRAT_WEBSITE_CACHE_PLUGIN_DIR . 'includes/AdminMenu.php';
        new AdminMenu();
    }
}
add_action('plugins_loaded', 'samrat_website_cache_init');

/**
 * Activation hook - set default options
 */
function samrat_website_cache_activate() {
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

    if (!get_option('samrat_website_cache_settings')) {
        add_option('samrat_website_cache_settings', $defaults);
    }

    // Create cache directory
    $cache_dir = SAMRAT_WEBSITE_CACHE_PLUGIN_DIR . 'cache/';
    if (!file_exists($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }

    // Create .htaccess for cache directory (deny direct access)
    $htaccess_file = $cache_dir . '.htaccess';
    if (!file_exists($htaccess_file)) {
        $htaccess_content = "Order deny,allow\nDeny from all";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($htaccess_file, $htaccess_content);
    }
}
register_activation_hook(__FILE__, 'samrat_website_cache_activate');

/**
 * Deactivation hook - clear cache
 */
function samrat_website_cache_deactivate() {
    // Clear all cache on deactivation
    $cache_dir = SAMRAT_WEBSITE_CACHE_PLUGIN_DIR . 'cache/';
    
    if (file_exists($cache_dir)) {
        $files = glob($cache_dir . '*.html');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink($file);
                }
            }
        }
    }
}
register_deactivation_hook(__FILE__, 'samrat_website_cache_deactivate');

/**
 * Add settings link on plugins page
 */
function samrat_website_cache_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=samrat-website-cache') . '">' . __('Settings', 'samrat-website-cache') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'samrat_website_cache_settings_link');

/**
 * Load admin bar script for frontend clear cache
 */
function samrat_website_cache_admin_bar_scripts() {
    if (!is_admin() && is_admin_bar_showing() && current_user_can('manage_options')) {
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', '
            var samratCache = {
                ajaxUrl: "' . admin_url('admin-ajax.php') . '",
                nonce: "' . wp_create_nonce('samrat_cache_nonce') . '",
                clearingText: "' . esc_js(__('Clearing...', 'samrat-website-cache')) . '",
                clearedText: "' . esc_js(__('Cache Cleared!', 'samrat-website-cache')) . '",
                errorText: "' . esc_js(__('Error clearing cache', 'samrat-website-cache')) . '"
            };
            function samratClearCacheFromBar() {
                jQuery.ajax({
                    url: samratCache.ajaxUrl,
                    type: "POST",
                    data: {
                        action: "samrat_clear_cache",
                        nonce: samratCache.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                        } else {
                            alert(response.data.message || samratCache.errorText);
                        }
                    },
                    error: function() {
                        alert(samratCache.errorText);
                    }
                });
            }
        ');
    }
}
add_action('wp_enqueue_scripts', 'samrat_website_cache_admin_bar_scripts');