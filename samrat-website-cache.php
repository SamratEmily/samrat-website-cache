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
 *
 * @package Samrat_Website_Cache
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Define Constants.
define('SAMRWECA_VERSION', '1.0.0');
define('SAMRWECA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAMRWECA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SAMRWECA_PLUGIN_FILE', __FILE__);
// Cache stored outside the plugin directory so it is not bundled with plugin
// updates and sits at the conventional wp-content/cache/ location.
// On Apache, a .htaccess inside the directory denies direct HTTP access.
// On nginx, add: location ~* /cache/samrat-website-cache/ { deny all; }
define('SAMRWECA_CACHE_DIR', WP_CONTENT_DIR . '/cache/samrat-website-cache/');

/**
 * Initialize the plugin.
 */
function samrweca_init() {
	// Load Cache Handler.
	require_once SAMRWECA_PLUGIN_DIR . 'includes/CacheHandler.php';
	new Samrweca_Cache_Handler();

	// Load Admin Menu (only in admin area).
	if (is_admin()) {
		require_once SAMRWECA_PLUGIN_DIR . 'includes/AdminMenu.php';
		new Samrweca_Admin_Menu();
	}
}
add_action('plugins_loaded', 'samrweca_init');

/**
 * Activation hook - set default options.
 */
function samrweca_activate() {
	$defaults = array(
		'enable_page_cache'  => 1,
		'cache_logged_users' => 0,
		'cache_expiry'       => 86400,
		'minify_html'        => 0,
		'minify_css'         => 0,
		'minify_js'          => 0,
		'exclude_pages'      => "/cart/\n/checkout/\n/my-account/",
		'exclude_cookies'    => "woocommerce_cart_hash\nwoocommerce_items_in_cart",
	);

	if (!get_option('samrweca_settings')) {
		add_option('samrweca_settings', $defaults);
	}

	// Create cache directory and security files using WP_Filesystem.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if (!WP_Filesystem()) {
		return; // Filesystem not available (e.g. requires FTP credentials); skip silently.
	}
	global $wp_filesystem;

	$cache_dir = SAMRWECA_CACHE_DIR;
	if (!$wp_filesystem->exists($cache_dir)) {
		$wp_filesystem->mkdir($cache_dir, FS_CHMOD_DIR, true);
	}

	// Create .htaccess for cache directory (deny direct access).
	$htaccess_file = $cache_dir . '.htaccess';
	if (!$wp_filesystem->exists($htaccess_file)) {
		$htaccess_content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>";
		$wp_filesystem->put_contents($htaccess_file, $htaccess_content);
	}

	// Create index.php to prevent directory listing.
	$index_file = $cache_dir . 'index.php';
	if (!$wp_filesystem->exists($index_file)) {
		$wp_filesystem->put_contents($index_file, '<?php // Silence is golden.');
	}
}
register_activation_hook(__FILE__, 'samrweca_activate');

/**
 * Deactivation hook - clear cache.
 */
function samrweca_deactivate() {
	// Clear all cache on deactivation using WP_Filesystem.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if (!WP_Filesystem()) {
		return; // Filesystem not available; skip cache clear.
	}
	global $wp_filesystem;

	$cache_dir = SAMRWECA_CACHE_DIR;

	if ($wp_filesystem->exists($cache_dir)) {
		// Use wp_filesystem->dirlist to clear files.
		$file_list = $wp_filesystem->dirlist($cache_dir);
		if ($file_list) {
			foreach ($file_list as $file_name => $file_info) {
				if ('f' === $file_info['type'] && 'html' === pathinfo($file_name, PATHINFO_EXTENSION)) {
					$wp_filesystem->delete($cache_dir . $file_name);
				}
			}
		}
	}
}
register_deactivation_hook(__FILE__, 'samrweca_deactivate');

/**
 * Add settings link on plugins page.
 *
 * @param array $links Existing plugin action links.
 * @return array Modified plugin action links.
 */
function samrweca_settings_link($links) {
	$settings_link = '<a href="' . admin_url('admin.php?page=samrweca-settings') . '">' . __('Settings', 'samrat-website-cache') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'samrweca_settings_link');

/**
 * Load admin bar script for frontend clear cache.
 */
function samrweca_admin_bar_scripts() {
	if (!is_admin() && is_admin_bar_showing() && current_user_can('manage_options')) {
		wp_enqueue_script('jquery');
		wp_add_inline_script('jquery', '
			var samrwecaCache = {
				ajaxUrl: "' . esc_js(admin_url('admin-ajax.php')) . '",
				nonce: "' . esc_js(wp_create_nonce('samrweca_nonce')) . '",
				clearingText: "' . esc_js(__('Clearing...', 'samrat-website-cache')) . '",
				clearedText: "' . esc_js(__('Cache Cleared!', 'samrat-website-cache')) . '",
				errorText: "' . esc_js(__('Error clearing cache', 'samrat-website-cache')) . '"
			};
			function samrwecaClearCacheFromBar() {
				jQuery.ajax({
					url: samrwecaCache.ajaxUrl,
					type: "POST",
					data: {
						action: "samrweca_clear_cache",
						nonce: samrwecaCache.nonce
					},
					success: function(response) {
						if (response.success) {
							alert(response.data.message);
						} else {
							alert(response.data.message || samrwecaCache.errorText);
						}
					},
					error: function() {
						alert(samrwecaCache.errorText);
					}
				});
			}
		');
	}
}
add_action('wp_enqueue_scripts', 'samrweca_admin_bar_scripts');
