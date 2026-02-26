<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterShortcode {
    const VERSION = '2.1.2';

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

        wp_register_script('lcni-filter', LCNI_URL . 'modules/filter/filter.js', ['lcni-main-js', 'lcni-watchlist'], $version, true);
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

        $dynamic_style = $this->build_dynamic_style_css();
        if ($dynamic_style !== '') {
            wp_add_inline_style('lcni-filter', $dynamic_style);
        }

        $settings = $this->table->get_settings();
        LCNI_Button_Style_Config::enqueue_frontend_assets('lcni-filter');
        $stock_page_slug = sanitize_title((string) get_option('lcni_watchlist_stock_page', ''));

        $stock_detail_url = $stock_page_slug !== '' ? home_url('/' . $stock_page_slug . '/') : '';

        $storage_key_suffix = substr(md5(wp_json_encode($settings['table_columns'] ?? [])), 0, 8);

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
            'tableSettingsStorageKey' => 'lcni_filter_visible_columns_v1_' . $storage_key_suffix,
            'defaultFilterValues' => $settings['default_filter_values'] ?? [],
            'buttonConfig' => LCNI_Button_Style_Config::get_config(),
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

        $rules = [];
        $map = [
            'text_color' => 'color',
            'background_color' => 'background-color',
            'border_color' => 'border-color',
        ];
        foreach ($map as $key => $prop) {
            $value = sanitize_hex_color((string) ($style[$key] ?? ''));
            if ($value) {
                $rules[] = $prop . ':' . $value;
            }
        }

        foreach (['border_width' => 'border-width', 'border_radius' => 'border-radius', 'header_label_font_size' => 'font-size'] as $key => $prop) {
            $raw = isset($style[$key]) ? trim((string) $style[$key]) : '';
            if ($raw !== '' && preg_match('/^\d+(\.\d+)?$/', $raw)) {
                $rules[] = $prop . ':' . $raw . 'px';
            }
        }

        $row_font_size = isset($style['row_font_size']) ? trim((string) $style['row_font_size']) : '';
        $header_row_height = isset($style['table_header_row_height']) ? trim((string) $style['table_header_row_height']) : '';
        $header_height_rule = '';
        if ($header_row_height !== '' && preg_match('/^\d+(\.\d+)?$/', $header_row_height)) {
            $header_height_rule = 'height:' . $header_row_height . 'px;';
        }
        $row_rule = '';
        if ($row_font_size !== '' && preg_match('/^\d+(\.\d+)?$/', $row_font_size)) {
            $row_rule = 'font-size:' . $row_font_size . 'px;';
        }

        $css = '';
        if (!empty($rules)) {
            $css .= '.lcni-filter-module .lcni-table th,.lcni-filter-module .lcni-table td{' . implode(';', $rules) . ';}' . "\n";
            $css .= '.lcni-filter-module .lcni-filter-panel,.lcni-filter-module .lcni-column-pop{' . implode(';', $rules) . ';}' . "\n";
        }
        if ($row_rule !== '') {
            $css .= '.lcni-filter-module .lcni-table td{' . $row_rule . "}\n";
        }
        if ($header_height_rule !== '') {
            $css .= '.lcni-filter-module .lcni-table th{' . $header_height_rule . "}\n";
        }

        return $css;
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
