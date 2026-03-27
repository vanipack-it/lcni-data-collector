<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterShortcode {
    const VERSION = '2.3.4';

    private $table;

    public function __construct(LCNI_FilterTable $table) {
        $this->table = $table;
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_enqueue_scripts', [$this, 'conditionally_enqueue_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_filter', [$this, 'render']);
        add_shortcode('lcni_filter', [$this, 'render']);
    }

    public function register_assets() {
        $js = LCNI_PATH . 'modules/filter/filter.js';
        $css = LCNI_PATH . 'modules/filter/filter.css';
        $version = file_exists($js) ? (string) filemtime($js) : self::VERSION;
        $css_version = file_exists($css) ? (string) filemtime($css) : self::VERSION;

        wp_register_script('lcni-filter', LCNI_URL . 'modules/filter/filter.js', ['lcni-main-js', 'lcni-watchlist', 'lcni-table-engine'], $version, true);
        wp_register_style('lcni-filter', LCNI_URL . 'modules/filter/filter.css', ['lcni-ui-table'], $css_version);
    }

    public function conditionally_enqueue_assets() {
        if (!$this->should_enqueue_assets()) {
            return;
        }

        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');
        wp_enqueue_script('lcni-filter');
        wp_enqueue_style('lcni-filter');

        $dynamic_style = $this->build_dynamic_style_css();
        if ($dynamic_style !== '') {
            wp_add_inline_style('lcni-filter', $dynamic_style);
        }

        $settings = $this->table->get_settings();
        LCNI_Button_Style_Config::enqueue_frontend_assets('lcni-filter');
        $stock_page_slug = sanitize_title((string) get_option('lcni_watchlist_stock_page', ''));
        $filter_page_slug = sanitize_title((string) get_option('lcni_filter_link_page', 'sug-filter'));
        $filter_login_page_id = absint(get_option('lcni_filter_login_page_id', 0));
        $filter_register_page_id = absint(get_option('lcni_filter_register_page_id', 0));

        $stock_detail_url = $stock_page_slug !== '' ? home_url('/' . $stock_page_slug . '/') : '';
        $login_url = $filter_login_page_id > 0 ? get_permalink($filter_login_page_id) : '';
        $register_url = $filter_register_page_id > 0 ? get_permalink($filter_register_page_id) : '';
        if (!is_string($login_url) || $login_url === '') {
            $login_url = wp_login_url(get_permalink() ?: home_url('/'));
        }
        if (!is_string($register_url) || $register_url === '') {
            $register_url = function_exists('wp_registration_url') ? wp_registration_url() : wp_login_url();
        }

        $storage_key_suffix = substr(md5(wp_json_encode($settings['table_columns'] ?? [])), 0, 8);

        wp_localize_script('lcni-filter', 'lcniFilterConfig', [
            'restUrl' => esc_url_raw(rest_url('lcni/v1/filter/list')),
            'savedFilterBase' => esc_url_raw(rest_url('lcni/v1/filter')),
            'watchlistRestBase' => esc_url_raw(rest_url('lcni/v1/watchlist')),
            'nonce' => wp_create_nonce('wp_rest'),
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => esc_url_raw($login_url),
            'registerUrl' => esc_url_raw($register_url),
            'stockDetailPageSlug' => $stock_page_slug,
            'stockDetailUrl' => esc_url_raw($stock_detail_url),
            'filterPageUrl' => esc_url_raw(home_url('/' . $filter_page_slug . '/')),
            'filterCriteriaColumns' => array_values(array_filter(array_map('sanitize_key', (array) get_option('lcni_filter_criteria_columns', [])))),
            'settings' => $settings,
            'criteria' => $this->table->get_criteria_definitions(),
            'tableSettingsStorageKey' => 'lcni_filter_visible_columns_v1_' . $storage_key_suffix,
            'defaultFilterValues' => $settings['default_filter_values'] ?? [],
            'buttonConfig' => LCNI_Button_Style_Config::get_config(),
            'globalTableConfig' => class_exists('LCNI_Table_Config') ? LCNI_Table_Config::get_config() : [],
        ]);
    }

    public function render() {
        return '<div class="lcni-app"><div class="lcni-stock-filter lcni-filter-module" data-lcni-stock-filter></div></div>';
    }

    private function build_dynamic_style_css() {
        $style = get_option('lcni_filter_style_config', get_option('lcni_filter_style', []));
        $style = is_array($style) ? $style : [];
        if (!empty($style['inherit_style'])) {
            return '';
        }

        return '';
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

        $content = (string) $post->post_content;

        return has_shortcode($content, 'lcni_stock_filter') || has_shortcode($content, 'lcni_filter');
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
