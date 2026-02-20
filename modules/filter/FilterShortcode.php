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
        $js = LCNI_PATH . 'modules/filter/assets/js/filter.js';
        $version = file_exists($js) ? (string) filemtime($js) : self::VERSION;

        wp_register_script('lcni-filter', LCNI_URL . 'modules/filter/assets/js/filter.js', ['lcni-watchlist'], $version, true);
    }

    public function render() {
        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');
        wp_enqueue_script('lcni-filter');

        wp_add_inline_script('lcni-watchlist', 'window.LCNIWatchlistConfig = window.LCNIWatchlistConfig || ' . wp_json_encode([
            'restUrl' => esc_url_raw(rest_url('lcni/v1/watchlist')),
            'nonce' => wp_create_nonce('wp_rest'),
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => wp_login_url((string) home_url('/')),
            'stockDetailPath' => get_option('lcni_watchlist_stock_page', ''),
            'allowedColumns' => [],
            'defaultColumnsDesktop' => [],
            'defaultColumnsMobile' => [],
            'columnLabels' => [],
            'valueColorRules' => [],
        ]) . ';', 'before');

        $settings = $this->table->get_settings();
        $style_attr = sprintf(
            '--lcni-watchlist-label-font-size:%1$dpx;--lcni-watchlist-row-font-size:%2$dpx;',
            max(10, min(30, (int) ($settings['styles']['label_font_size'] ?? 12))),
            max(10, min(30, (int) ($settings['styles']['row_font_size'] ?? 13)))
        );

        wp_localize_script('lcni-filter', 'LCNIFilterConfig', [
            'restUrl' => esc_url_raw(rest_url('lcni/v1/filter/list')),
            'nonce' => wp_create_nonce('wp_rest'),
            'mode' => 'all_symbols',
            'settings' => $settings,
        ]);

        return sprintf('<div class="lcni-watchlist lcni-stock-filter" data-lcni-stock-filter style="%s"></div>', esc_attr($style_attr));
    }
}
