<?php

/**
 * Admin Menu Class for Samrat Website Cache
 *
 * @package Samrat_Website_Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

class AdminMenu {

    /**
     * Option name for storing cache settings
     */
    const OPTION_NAME = 'samrat_website_cache_settings';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_samrat_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Website Cache', 'samrat-website-cache'),
            __('Website Cache', 'samrat-website-cache'),
            'manage_options',
            'samrat-website-cache',
            array($this, 'render_settings_page'),
            'dashicons-performance',
            81
        );

        add_submenu_page(
            'samrat-website-cache',
            __('Cache Settings', 'samrat-website-cache'),
            __('Settings', 'samrat-website-cache'),
            'manage_options',
            'samrat-website-cache',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'samrat-website-cache',
            __('Clear Cache', 'samrat-website-cache'),
            __('Clear Cache', 'samrat-website-cache'),
            'manage_options',
            'samrat-cache-clear',
            array($this, 'render_clear_cache_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'samrat_cache_settings_group',
            self::OPTION_NAME,
            array($this, 'sanitize_settings')
        );

        // Page Cache Section
        add_settings_section(
            'samrat_cache_page_section',
            __('Page Cache Settings', 'samrat-website-cache'),
            array($this, 'page_section_callback'),
            'samrat-website-cache'
        );

        add_settings_field(
            'enable_page_cache',
            __('Enable Page Cache', 'samrat-website-cache'),
            array($this, 'render_toggle_field'),
            'samrat-website-cache',
            'samrat_cache_page_section',
            array(
                'id' => 'enable_page_cache',
                'description' => __('Enable full page caching for faster page loads.', 'samrat-website-cache')
            )
        );

        add_settings_field(
            'cache_logged_users',
            __('Cache for Logged-in Users', 'samrat-website-cache'),
            array($this, 'render_toggle_field'),
            'samrat-website-cache',
            'samrat_cache_page_section',
            array(
                'id' => 'cache_logged_users',
                'description' => __('Enable caching for logged-in users. Not recommended for dynamic content.', 'samrat-website-cache')
            )
        );

        add_settings_field(
            'cache_expiry',
            __('Cache Expiry Time', 'samrat-website-cache'),
            array($this, 'render_select_field'),
            'samrat-website-cache',
            'samrat_cache_page_section',
            array(
                'id' => 'cache_expiry',
                'options' => array(
                    '3600' => __('1 Hour', 'samrat-website-cache'),
                    '7200' => __('2 Hours', 'samrat-website-cache'),
                    '21600' => __('6 Hours', 'samrat-website-cache'),
                    '43200' => __('12 Hours', 'samrat-website-cache'),
                    '86400' => __('24 Hours', 'samrat-website-cache'),
                    '604800' => __('1 Week', 'samrat-website-cache'),
                ),
                'description' => __('How long to keep cached pages before regenerating.', 'samrat-website-cache')
            )
        );

        // Minification Section
        add_settings_section(
            'samrat_cache_minify_section',
            __('Minification Settings', 'samrat-website-cache'),
            array($this, 'minify_section_callback'),
            'samrat-website-cache'
        );

        add_settings_field(
            'minify_html',
            __('Minify HTML', 'samrat-website-cache'),
            array($this, 'render_toggle_field'),
            'samrat-website-cache',
            'samrat_cache_minify_section',
            array(
                'id' => 'minify_html',
                'description' => __('Remove unnecessary whitespace from HTML output.', 'samrat-website-cache')
            )
        );

        add_settings_field(
            'minify_css',
            __('Minify CSS', 'samrat-website-cache'),
            array($this, 'render_toggle_field'),
            'samrat-website-cache',
            'samrat_cache_minify_section',
            array(
                'id' => 'minify_css',
                'description' => __('Minify inline CSS styles in the HTML.', 'samrat-website-cache')
            )
        );

        add_settings_field(
            'minify_js',
            __('Minify JavaScript', 'samrat-website-cache'),
            array($this, 'render_toggle_field'),
            'samrat-website-cache',
            'samrat_cache_minify_section',
            array(
                'id' => 'minify_js',
                'description' => __('Minify inline JavaScript in the HTML.', 'samrat-website-cache')
            )
        );

        // Exclusions Section
        add_settings_section(
            'samrat_cache_exclusion_section',
            __('Cache Exclusions', 'samrat-website-cache'),
            array($this, 'exclusion_section_callback'),
            'samrat-website-cache'
        );

        add_settings_field(
            'exclude_pages',
            __('Exclude Pages', 'samrat-website-cache'),
            array($this, 'render_textarea_field'),
            'samrat-website-cache',
            'samrat_cache_exclusion_section',
            array(
                'id' => 'exclude_pages',
                'description' => __('Enter page URLs to exclude from caching (one per line). Example: /checkout/, /cart/', 'samrat-website-cache')
            )
        );

        add_settings_field(
            'exclude_cookies',
            __('Exclude Cookies', 'samrat-website-cache'),
            array($this, 'render_textarea_field'),
            'samrat-website-cache',
            'samrat_cache_exclusion_section',
            array(
                'id' => 'exclude_cookies',
                'description' => __('Do not cache if these cookies are present (one per line). Example: woocommerce_cart_hash', 'samrat-website-cache')
            )
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Boolean fields
        $boolean_fields = array('enable_page_cache', 'cache_logged_users', 'minify_html', 'minify_css', 'minify_js');
        foreach ($boolean_fields as $field) {
            $sanitized[$field] = isset($input[$field]) ? 1 : 0;
        }
        
        // Select fields - whitelist against the values offered in the UI
        $allowed_expiry = array(3600, 7200, 21600, 43200, 86400, 604800);
        $expiry = isset($input['cache_expiry']) ? absint($input['cache_expiry']) : 86400;
        $sanitized['cache_expiry'] = in_array($expiry, $allowed_expiry, true) ? $expiry : 86400;
        
        // Textarea fields
        $sanitized['exclude_pages'] = isset($input['exclude_pages']) ? sanitize_textarea_field($input['exclude_pages']) : '';
        $sanitized['exclude_cookies'] = isset($input['exclude_cookies']) ? sanitize_textarea_field($input['exclude_cookies']) : '';
        
        return $sanitized;
    }

    /**
     * Page section callback
     */
    public function page_section_callback() {
        echo '<p class="description">' . esc_html__('Configure page caching options to improve site performance.', 'samrat-website-cache') . '</p>';
    }

    /**
     * Minify section callback
     */
    public function minify_section_callback() {
        echo '<p class="description">' . esc_html__('Minification reduces file sizes by removing unnecessary characters.', 'samrat-website-cache') . '</p>';
    }

    /**
     * Exclusion section callback
     */
    public function exclusion_section_callback() {
        echo '<p class="description">' . esc_html__('Specify pages or conditions to exclude from caching.', 'samrat-website-cache') . '</p>';
    }

    /**
     * Render toggle/checkbox field
     */
    public function render_toggle_field($args) {
        $options = get_option(self::OPTION_NAME, array());
        $value = isset($options[$args['id']]) ? $options[$args['id']] : 0;
        ?>
        <label class="samrat-toggle-switch">
            <input type="checkbox" 
                   name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['id'] . ']'); ?>" 
                   id="<?php echo esc_attr($args['id']); ?>" 
                   value="1" 
                   <?php checked($value, 1); ?>>
            <span class="samrat-toggle-slider"></span>
        </label>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Render select field
     */
    public function render_select_field($args) {
        $options = get_option(self::OPTION_NAME, array());
        $value = isset($options[$args['id']]) ? $options[$args['id']] : '86400';
        ?>
        <select name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['id'] . ']'); ?>" 
                id="<?php echo esc_attr($args['id']); ?>" 
                class="samrat-select">
            <?php foreach ($args['options'] as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Render textarea field
     */
    public function render_textarea_field($args) {
        $options = get_option(self::OPTION_NAME, array());
        $value = isset($options[$args['id']]) ? $options[$args['id']] : '';
        ?>
        <textarea name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['id'] . ']'); ?>" 
                  id="<?php echo esc_attr($args['id']); ?>" 
                  rows="4" 
                  cols="50" 
                  class="large-text code"><?php echo esc_textarea($value); ?></textarea>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'samrat-website-cache') === false && strpos($hook, 'samrat-cache') === false) {
            return;
        }

        wp_enqueue_style(
            'samrat-cache-admin',
            SAMRAT_WEBSITE_CACHE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SAMRAT_WEBSITE_CACHE_VERSION
        );

        wp_enqueue_script(
            'samrat-cache-admin',
            SAMRAT_WEBSITE_CACHE_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            SAMRAT_WEBSITE_CACHE_VERSION,
            true
        );

        wp_localize_script('samrat-cache-admin', 'samratCache', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('samrat_cache_nonce'),
            'clearingText' => __('Clearing...', 'samrat-website-cache'),
            'clearedText' => __('Cache Cleared!', 'samrat-website-cache'),
            'errorText' => __('Error clearing cache', 'samrat-website-cache'),
        ));
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Check if settings were saved
        $settings_updated = false;
        if (isset($_GET['settings-updated'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $settings_updated = sanitize_text_field(wp_unslash($_GET['settings-updated'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ($settings_updated) {
            add_settings_error(
                'samrat_cache_messages',
                'samrat_cache_message',
                __('Settings Saved', 'samrat-website-cache'),
                'updated'
            );
        }

        $options = get_option(self::OPTION_NAME, $this->get_default_options());
        $cache_stats = $this->get_cache_stats();
        ?>
        <div class="wrap samrat-cache-wrap">
            <div class="samrat-cache-header">
                <div class="samrat-cache-header-content">
                    <h1>
                        <span class="dashicons dashicons-performance"></span>
                        <?php esc_html_e('Website Cache Settings', 'samrat-website-cache'); ?>
                    </h1>
                    <p class="samrat-cache-tagline"><?php esc_html_e('Optimize your website performance with advanced caching', 'samrat-website-cache'); ?></p>
                </div>
                <div class="samrat-cache-version">
                    <?php 
                    /* translators: %s: Plugin version */
                    printf(esc_html__('Version %s', 'samrat-website-cache'), esc_html(SAMRAT_WEBSITE_CACHE_VERSION)); ?>
                </div>
            </div>

            <?php settings_errors('samrat_cache_messages'); ?>

            <div class="samrat-cache-dashboard">
                <!-- Stats Cards -->
                <div class="samrat-cache-stats-grid">
                    <div class="samrat-cache-stat-card">
                        <div class="stat-icon cached">
                            <span class="dashicons dashicons-database"></span>
                        </div>
                        <div class="stat-content">
                            <span class="stat-value"><?php echo esc_html($cache_stats['total_files']); ?></span>
                            <span class="stat-label"><?php esc_html_e('Cached Pages', 'samrat-website-cache'); ?></span>
                        </div>
                    </div>
                    
                    <div class="samrat-cache-stat-card">
                        <div class="stat-icon size">
                            <span class="dashicons dashicons-chart-area"></span>
                        </div>
                        <div class="stat-content">
                            <span class="stat-value"><?php echo esc_html($cache_stats['total_size']); ?></span>
                            <span class="stat-label"><?php esc_html_e('Cache Size', 'samrat-website-cache'); ?></span>
                        </div>
                    </div>
                    
                    <div class="samrat-cache-stat-card">
                        <div class="stat-icon status <?php echo esc_attr($options['enable_page_cache'] ? 'active' : 'inactive'); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($options['enable_page_cache'] ? 'yes-alt' : 'dismiss'); ?>"></span>
                        </div>
                        <div class="stat-content">
                            <span class="stat-value"><?php echo $options['enable_page_cache'] ? esc_html__('Active', 'samrat-website-cache') : esc_html__('Inactive', 'samrat-website-cache'); ?></span>
                            <span class="stat-label"><?php esc_html_e('Cache Status', 'samrat-website-cache'); ?></span>
                        </div>
                    </div>
                    
                    <div class="samrat-cache-stat-card samrat-cache-clear-card">
                        <button type="button" class="samrat-clear-cache-btn" id="samrat-clear-all-cache">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Clear All Cache', 'samrat-website-cache'); ?>
                        </button>
                    </div>
                </div>

                <!-- Settings Form -->
                <div class="samrat-cache-settings-container">
                    <form method="post" action="options.php" class="samrat-cache-form">
                        <?php
                        settings_fields('samrat_cache_settings_group');
                        do_settings_sections('samrat-website-cache');
                        submit_button(__('Save Settings', 'samrat-website-cache'), 'primary samrat-save-btn');
                        ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render clear cache page
     */
    public function render_clear_cache_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $cache_stats = $this->get_cache_stats();
        ?>
        <div class="wrap samrat-cache-wrap">
            <div class="samrat-cache-header">
                <div class="samrat-cache-header-content">
                    <h1>
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e('Clear Cache', 'samrat-website-cache'); ?>
                    </h1>
                    <p class="samrat-cache-tagline"><?php esc_html_e('Remove cached pages to reflect recent changes', 'samrat-website-cache'); ?></p>
                </div>
            </div>

            <div class="samrat-cache-clear-container">
                <div class="samrat-cache-clear-info">
                    <div class="cache-info-card">
                        <h3><?php esc_html_e('Cache Statistics', 'samrat-website-cache'); ?></h3>
                        <ul>
                            <li>
                                <span class="label"><?php esc_html_e('Total Cached Files:', 'samrat-website-cache'); ?></span>
                                <span class="value"><?php echo esc_html($cache_stats['total_files']); ?></span>
                            </li>
                            <li>
                                <span class="label"><?php esc_html_e('Total Cache Size:', 'samrat-website-cache'); ?></span>
                                <span class="value"><?php echo esc_html($cache_stats['total_size']); ?></span>
                            </li>
                            <li>
                                <span class="label"><?php esc_html_e('Cache Directory:', 'samrat-website-cache'); ?></span>
                                <span class="value code">/wp-content/cache/samrat-website-cache/</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="samrat-cache-clear-actions">
                    <div class="clear-action-card">
                        <div class="action-icon">
                            <span class="dashicons dashicons-trash"></span>
                        </div>
                        <h3><?php esc_html_e('Clear All Cache', 'samrat-website-cache'); ?></h3>
                        <p><?php esc_html_e('Remove all cached pages. New cache will be generated as visitors browse your site.', 'samrat-website-cache'); ?></p>
                        <button type="button" class="button button-primary button-large samrat-clear-cache-btn" id="samrat-clear-all-cache-page">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Clear All Cache', 'samrat-website-cache'); ?>
                        </button>
                    </div>

                    <div class="clear-action-card">
                        <div class="action-icon refresh">
                            <span class="dashicons dashicons-update"></span>
                        </div>
                        <h3><?php esc_html_e('Automatic Cache Clear', 'samrat-website-cache'); ?></h3>
                        <p><?php esc_html_e('Cache is automatically cleared when posts are updated, themes changed, or plugins activated/deactivated.', 'samrat-website-cache'); ?></p>
                        <span class="status-badge active">
                            <span class="dashicons dashicons-yes"></span>
                            <?php esc_html_e('Enabled', 'samrat-website-cache'); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Add admin bar menu
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'id'    => 'samrat-cache',
            'title' => '<span class="ab-icon dashicons dashicons-performance" style="margin-top:2px"></span>' . __('Cache', 'samrat-website-cache'),
            'href'  => admin_url('admin.php?page=samrat-website-cache'),
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'samrat-cache',
            'id'     => 'samrat-cache-settings',
            'title'  => __('Settings', 'samrat-website-cache'),
            'href'   => admin_url('admin.php?page=samrat-website-cache'),
        ));

        $wp_admin_bar->add_node(array(
            'parent' => 'samrat-cache',
            'id'     => 'samrat-cache-clear',
            'title'  => __('Clear Cache', 'samrat-website-cache'),
            'href'   => '#',
            'meta'   => array(
                'onclick' => 'samratClearCacheFromBar(); return false;'
            ),
        ));
    }

    /**
     * AJAX handler for clearing cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('samrat_cache_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'samrat-website-cache')));
        }

        $result = $this->clear_all_cache();

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: 1: number of files, 2: total size */
                    __('Successfully cleared %1$d cached files (%2$s)', 'samrat-website-cache'),
                    $result['files_deleted'],
                    $result['size_cleared']
                )
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    /**
     * Clear all cache files
     */
    public function clear_all_cache() {
        $cache_dir = SAMRAT_WEBSITE_CACHE_DIR;
        
        if (!file_exists($cache_dir)) {
            return array(
                'success' => true,
                'files_deleted' => 0,
                'size_cleared' => '0 B'
            );
        }

        $files_deleted = 0;
        $total_size = 0;

        $files = glob($cache_dir . '*.html');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    $total_size += filesize($file);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    if (unlink($file)) {
                        $files_deleted++;
                    }
                }
            }
        }

        return array(
            'success' => true,
            'files_deleted' => $files_deleted,
            'size_cleared' => $this->format_bytes($total_size)
        );
    }

    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        $cache_dir = SAMRAT_WEBSITE_CACHE_DIR;
        $total_files = 0;
        $total_size = 0;

        if (file_exists($cache_dir)) {
            $files = glob($cache_dir . '*.html');
            if ($files) {
                $total_files = count($files);
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $total_size += filesize($file);
                    }
                }
            }
        }

        return array(
            'total_files' => $total_files,
            'total_size' => $this->format_bytes($total_size)
        );
    }

    /**
     * Format bytes to human readable size
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Get default options
     */
    public static function get_default_options() {
        return array(
            'enable_page_cache' => 1,
            'cache_logged_users' => 0,
            'cache_expiry' => 86400,
            'minify_html' => 0,
            'minify_css' => 0,
            'minify_js' => 0,
            'exclude_pages' => "/cart/\n/checkout/\n/my-account/",
            'exclude_cookies' => "woocommerce_cart_hash\nwoocommerce_items_in_cart",
        );
    }

    /**
     * Static method to get settings
     */
    public static function get_settings() {
        $defaults = self::get_default_options();
        $options  = get_option(self::OPTION_NAME, $defaults);
        return wp_parse_args($options, $defaults);
    }
}
