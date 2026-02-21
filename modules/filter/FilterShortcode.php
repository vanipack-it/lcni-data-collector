<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterShortcode {
    const VERSION = '2.0.3';

    private $table;

    public function __construct(LCNI_FilterTable $table) {
        $this->table = $table;
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_enqueue_scripts', [$this, 'conditionally_enqueue_assets']);
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

    public function conditionally_enqueue_assets() {
        if (!$this->should_enqueue_assets()) {
            return;
        }

        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');
        wp_enqueue_script('lcni-filter');
        wp_enqueue_style('lcni-filter');

        $settings = $this->table->get_settings();
        LCNI_Button_Style_Config::enqueue_frontend_assets('lcni-filter');
        $stock_page_slug = sanitize_title((string) get_option('lcni_watchlist_stock_page', ''));

        $stock_detail_url = $stock_page_slug !== '' ? home_url('/' . $stock_page_slug . '/') : '';

        wp_localize_script('lcni-filter', 'lcniFilterConfig', [
            'restUrl' => esc_url_raw(rest_url('lcni/v1/filter/list')),
            'savedFilterBase' => esc_url_raw(rest_url('lcni/v1/filter')),
            'watchlistRestBase' => esc_url_raw(rest_url('lcni/v1/watchlist')),
            'nonce' => wp_create_nonce('wp_rest'),
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => esc_url_raw(wp_login_url(get_permalink() ?: home_url('/'))),
            'registerUrl' => esc_url_raw(function_exists('wp_registration_url') ? wp_registration_url() : wp_login_url()),
            'stockDetailPageSlug' => $stock_page_slug,
            'stockDetailUrl' => esc_url_raw($stock_detail_url),
            'settings' => $settings,
            'criteria' => $this->table->get_criteria_definitions(),
            'tableSettingsStorageKey' => 'lcni_filter_visible_columns_v1',
            'defaultFilterValues' => $settings['default_filter_values'] ?? [],
            'buttonConfig' => LCNI_Button_Style_Config::get_config(),
        ]);
    }

    public function render() {
        return '<div class="lcni-app"><div class="lcni-stock-filter" data-lcni-stock-filter></div></div>';
    }

    private function should_enqueue_assets() {
        if ($this->is_stock_detail_context()) {
            return true;
        }

        if (!is_singular()) {
            return false;
        }

        $post = get_post();
        if (!$post instanceof WP_Post) {
            return false;
        }

        return has_shortcode((string) $post->post_content, 'lcni_stock_filter');
    }

    private function is_stock_detail_context() {
        $symbol = get_query_var('symbol');
        if (is_string($symbol) && $symbol !== '') {
            return true;
        }

        $router_symbol = get_query_var(LCNI_Stock_Detail_Router::STOCK_QUERY_VAR);

        return is_string($router_symbol) && $router_symbol !== '';
    }
}
