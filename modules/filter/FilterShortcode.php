<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterShortcode {
    const VERSION = '1.0.0';

    private $table;

    public function __construct(LCNI_FilterTable $table) {
        $this->table = $table;
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_filter', [$this, 'render']);
    }

    public function register_assets() {
        $js = LCNI_PATH . 'modules/filter/filter.js';
        $css = LCNI_PATH . 'modules/filter/filter.css';
        $version = file_exists($js) ? (string) filemtime($js) : self::VERSION;
        $css_version = file_exists($css) ? (string) filemtime($css) : self::VERSION;

        wp_register_script('lcni-filter', LCNI_URL . 'modules/filter/filter.js', ['lcni-watchlist'], $version, true);
        wp_register_style('lcni-filter', LCNI_URL . 'modules/filter/filter.css', [], $css_version);
    }

    public function render() {
        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');
        wp_enqueue_script('lcni-filter');
        wp_enqueue_style('lcni-filter');

        $settings = $this->table->get_settings();
        $stock_page_slug = sanitize_title((string) get_option('lcni_watchlist_stock_page', ''));

        wp_localize_script('lcni-filter', 'lcniFilterConfig', [
            'restUrl' => esc_url_raw(rest_url('lcni/v1/filter/list')),
            'watchlistRestBase' => esc_url_raw(rest_url('lcni/v1/watchlist')),
            'nonce' => wp_create_nonce('wp_rest'),
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => esc_url_raw(wp_login_url(get_permalink() ?: home_url('/'))),
            'stockDetailPageSlug' => $stock_page_slug,
            'settings' => $settings,
            'tableSettingsStorageKey' => 'lcni_filter_visible_columns_v1',
        ]);

        return '<div class="lcni-stock-filter" data-lcni-stock-filter></div>';
    }
}
