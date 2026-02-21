<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterShortcode {
    const VERSION = '2.0.1';

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
        wp_register_style('lcni-font-awesome-6', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css', [], '6.5.2');
    }

    public function conditionally_enqueue_assets() {
        if (!$this->should_enqueue_assets()) {
            return;
        }

        wp_enqueue_style('lcni-font-awesome-6');
        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');
        wp_enqueue_script('lcni-filter');
        wp_enqueue_style('lcni-filter');

        $settings = $this->table->get_settings();
        $button_style = $this->table->get_button_style_config();
        $this->enqueue_button_style($button_style);
        $stock_page_slug = sanitize_title((string) get_option('lcni_watchlist_stock_page', ''));

        wp_localize_script('lcni-filter', 'lcniFilterConfig', [
            'restUrl' => esc_url_raw(rest_url('lcni/v1/filter/list')),
            'watchlistRestBase' => esc_url_raw(rest_url('lcni/v1/watchlist')),
            'nonce' => wp_create_nonce('wp_rest'),
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => esc_url_raw(wp_login_url(get_permalink() ?: home_url('/'))),
            'stockDetailPageSlug' => $stock_page_slug,
            'settings' => $settings,
            'criteria' => $this->table->get_criteria_definitions(),
            'tableSettingsStorageKey' => 'lcni_filter_visible_columns_v1',
            'defaultFilterValues' => $settings['default_filter_values'] ?? [],
        ]);
    }

    public function render() {
        return '<div class="lcni-app"><div class="lcni-stock-filter" data-lcni-stock-filter></div></div>';
    }


    private function enqueue_button_style(array $button_style) {
        $css = sprintf(
            '.lcni-btn{background:%1$s;color:%2$s;height:%3$dpx;border-radius:%4$dpx;display:inline-flex;align-items:center;justify-content:center;gap:6px;}',
            esc_attr((string) ($button_style['button_background_color'] ?? '#2563eb')),
            esc_attr((string) ($button_style['button_text_color'] ?? '#ffffff')),
            (int) ($button_style['button_height'] ?? 34),
            (int) ($button_style['button_border_radius'] ?? 8)
        );

        wp_add_inline_style('lcni-filter', $css);
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
