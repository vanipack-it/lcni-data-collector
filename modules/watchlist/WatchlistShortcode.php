<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistShortcode {

    const OPTION_KEY = 'lcni_watchlist_settings';
    const VERSION = '1.0.0';

    private $service;

    public function __construct(LCNI_WatchlistService $service) {
        $this->service = $service;

        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_filter('lcni_render_symbol', [$this, 'inject_global_watchlist_button'], 10, 2);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_watchlist', [$this, 'render_watchlist']);
        add_shortcode('lcni_watchlist_add', [$this, 'render_add_button']);
    }

    public function register_assets() {
        $js = LCNI_PATH . 'modules/watchlist/assets/js/watchlist.js';
        $css = LCNI_PATH . 'modules/watchlist/assets/css/watchlist.css';
        $version = file_exists($js) ? (string) filemtime($js) : self::VERSION;
        $css_version = file_exists($css) ? (string) filemtime($css) : self::VERSION;

        wp_register_script('lcni-watchlist', LCNI_URL . 'modules/watchlist/assets/js/watchlist.js', [], $version, true);
        wp_register_style('lcni-watchlist', LCNI_URL . 'modules/watchlist/assets/css/watchlist.css', [], $css_version);
    }

    public function render_watchlist() {
        $this->enqueue_watchlist_assets();

        return '<div class="lcni-watchlist" data-lcni-watchlist></div>';
    }

    public function render_add_button($atts = []) {
        $atts = shortcode_atts(['symbol' => ''], $atts, 'lcni_watchlist_add');
        $symbol = strtoupper(sanitize_text_field((string) $atts['symbol']));

        if ($symbol === '') {
            return '';
        }

        $this->enqueue_watchlist_assets();

        $settings = $this->get_settings();
        $button_styles = isset($settings['add_button']) && is_array($settings['add_button']) ? $settings['add_button'] : [];
        $icon_class = isset($button_styles['icon']) ? sanitize_text_field($button_styles['icon']) : 'fa-solid fa-heart-circle-plus';

        $style = sprintf(
            'background:%s;color:%s;font-size:%dpx;',
            esc_attr($button_styles['background'] ?? '#dc2626'),
            esc_attr($button_styles['text_color'] ?? '#ffffff'),
            (int) ($button_styles['font_size'] ?? 14)
        );

        return sprintf(
            '<button type="button" class="lcni-watchlist-add" data-lcni-watchlist-add data-symbol="%1$s" style="%2$s"><i class="%3$s" aria-hidden="true"></i></button>',
            esc_attr($symbol),
            esc_attr($style),
            esc_attr($icon_class)
        );
    }

    public function inject_global_watchlist_button($symbol_html, $symbol) {
        $symbol = strtoupper(sanitize_text_field((string) $symbol));
        if ($symbol === '') {
            return (string) $symbol_html;
        }

        return sprintf(
            '<span class="lcni-symbol-with-watchlist">%1$s %2$s</span>',
            (string) $symbol_html,
            $this->render_add_button(['symbol' => $symbol])
        );
    }

    private function enqueue_watchlist_assets() {
        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');

        $settings = $this->get_settings();
        $stock_page_slug = sanitize_title((string) get_option('lcni_watchlist_stock_page', (string) ($settings['stock_detail_page_slug'] ?? '')));
        if ($stock_page_slug === '') {
            $stock_page_id = absint(get_option('lcni_frontend_stock_detail_page', 0));
            if ($stock_page_id > 0) {
                $stock_page_slug = sanitize_title((string) get_post_field('post_name', $stock_page_id));
            }
        }

        wp_localize_script('lcni-watchlist', 'lcniWatchlistConfig', [
            'restBase' => esc_url_raw(rest_url('lcni/v1/watchlist')),
            'settingsOption' => $settings,
            'nonce' => wp_create_nonce('wp_rest'),
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => esc_url_raw(wp_login_url(get_permalink() ?: home_url('/'))),
            'stockDetailPageSlug' => $stock_page_slug,
            'settingsStorageKey' => 'lcni_watchlist_settings_v1',
            'defaultColumnsDesktop' => $this->service->get_default_columns('desktop'),
            'defaultColumnsMobile' => $this->service->get_default_columns('mobile'),
        ]);
    }

    private function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);
        $defaults = [
            'allowed_columns' => $this->service->get_default_columns('desktop'),
            'default_columns_desktop' => $this->service->get_default_columns('desktop'),
            'default_columns_mobile' => $this->service->get_default_columns('mobile'),
            'stock_detail_page_slug' => sanitize_title((string) get_option('lcni_watchlist_stock_page', '')),
            'styles' => [
                'font' => 'inherit',
                'text_color' => '#111827',
                'background' => '#ffffff',
                'border' => '1px solid #e5e7eb',
                'border_radius' => 8,
            ],
            'add_button' => [
                'icon' => 'fa-solid fa-heart-circle-plus',
                'background' => '#dc2626',
                'text_color' => '#ffffff',
                'font_size' => 14,
            ],
        ];

        return wp_parse_args($saved, $defaults);
    }
}
