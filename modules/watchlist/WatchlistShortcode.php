<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistShortcode {

    const OPTION_KEY = 'lcni_watchlist_settings';
    const VERSION = '2.0.1';

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
        add_shortcode('lcni_watchlist_add_button', [$this, 'render_add_button']);
        add_shortcode('lcni_watchlist_add_form', [$this, 'render_add_form']);
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

        $styles = (array) ($this->get_settings()['styles'] ?? []);
        $style_attr = sprintf(
            '--lcni-watchlist-label-font-size:%1$dpx;--lcni-watchlist-row-font-size:%2$dpx;',
            max(10, min(30, (int) ($styles['label_font_size'] ?? 12))),
            max(10, min(30, (int) ($styles['row_font_size'] ?? 13)))
        );

        return sprintf('<div class="lcni-watchlist" data-lcni-watchlist style="%s"></div>', esc_attr($style_attr));
    }

    public function render_add_form() {
        $this->enqueue_watchlist_assets();
        $settings = $this->get_settings();
        $form_button = isset($settings['add_form_button']) && is_array($settings['add_form_button']) ? $settings['add_form_button'] : [];
        $icon_class = isset($form_button['icon']) ? sanitize_text_field($form_button['icon']) : 'fas fa-heart';
        $style = sprintf(
            'background:%s;color:%s;font-size:%dpx;height:%dpx;',
            esc_attr($form_button['background'] ?? '#2563eb'),
            esc_attr($form_button['text_color'] ?? '#ffffff'),
            (int) ($form_button['font_size'] ?? 14),
            (int) ($form_button['height'] ?? 34)
        );

        return sprintf('<form class="lcni-watchlist-add-form" data-lcni-watchlist-add-form><input type="text" data-watchlist-symbol-input placeholder="Nhập mã cổ phiếu" autocomplete="off" /><button type="submit" class="lcni-btn" style="%1$s"><i class="%2$s" aria-hidden="true"></i><span>Thêm</span></button></form>', esc_attr($style), esc_attr($icon_class));
    }

    public function render_add_button($atts = []) {
        $atts = shortcode_atts(['symbol' => ''], $atts, 'lcni_watchlist_add_button');
        $symbol = $this->resolve_symbol($atts['symbol']);

        if ($symbol === '') {
            return '';
        }

        $this->enqueue_watchlist_assets();

        $settings = $this->get_settings();
        $button_styles = isset($settings['add_button']) && is_array($settings['add_button']) ? $settings['add_button'] : [];
        $icon_class = isset($button_styles['icon']) ? sanitize_text_field($button_styles['icon']) : 'fas fa-heart';

        $style = sprintf(
            'background:%s;color:%s;font-size:%dpx;',
            esc_attr($button_styles['background'] ?? '#dc2626'),
            esc_attr($button_styles['text_color'] ?? '#ffffff'),
            (int) ($button_styles['font_size'] ?? 14)
        );
        $style .= sprintf('width:%dpx;height:%dpx;', (int) ($button_styles['size'] ?? 26), (int) ($button_styles['size'] ?? 26));

        return sprintf(
            '<button type="button" class="lcni-watchlist-add lcni-btn" data-lcni-watchlist-add data-symbol="%1$s" style="%2$s" aria-label="Add to watchlist"><i class="%3$s" aria-hidden="true"></i></button>',
            esc_attr($symbol),
            esc_attr($style),
            esc_attr($icon_class)
        );
    }

    public function inject_global_watchlist_button($symbol_html, $symbol) {
        $symbol = $this->resolve_symbol($symbol);
        if ($symbol === '') {
            return (string) $symbol_html;
        }

        return sprintf(
            '<span class="lcni-symbol-with-watchlist">%1$s %2$s</span>',
            (string) $symbol_html,
            $this->render_add_button(['symbol' => $symbol])
        );
    }

    private function resolve_symbol($symbol) {
        $symbol = strtoupper(trim(sanitize_text_field((string) $symbol)));
        if ($symbol !== '') {
            return $symbol;
        }

        $query_symbol = isset($_GET['symbol']) ? wp_unslash($_GET['symbol']) : '';
        $query_symbol = strtoupper(trim(sanitize_text_field((string) $query_symbol)));

        return $query_symbol;
    }

    private function enqueue_watchlist_assets() {
        wp_enqueue_script('lcni-watchlist');
        wp_enqueue_style('lcni-watchlist');

        $button_style = $this->get_button_style_config();
        $this->enqueue_button_style($button_style);

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
            'buttonStyle' => $button_style,
        ]);
    }


    private function get_button_style_config() {
        $saved = get_option('lcni_button_style_config', []);
        $saved = is_array($saved) ? $saved : [];

        return [
            'button_background_color' => sanitize_hex_color((string) ($saved['button_background_color'] ?? '#2563eb')) ?: '#2563eb',
            'button_text_color' => sanitize_hex_color((string) ($saved['button_text_color'] ?? '#ffffff')) ?: '#ffffff',
            'button_height' => max(28, min(56, (int) ($saved['button_height'] ?? 34))),
            'button_border_radius' => max(0, min(30, (int) ($saved['button_border_radius'] ?? 8))),
            'button_icon_class' => sanitize_text_field((string) ($saved['button_icon_class'] ?? 'fas fa-sliders-h')),
        ];
    }

    private function enqueue_button_style(array $button_style) {
        $css = sprintf(
            '.lcni-btn{background:%1$s;color:%2$s;height:%3$dpx;border-radius:%4$dpx;display:inline-flex;align-items:center;justify-content:center;gap:6px;}',
            esc_attr((string) ($button_style['button_background_color'] ?? '#2563eb')),
            esc_attr((string) ($button_style['button_text_color'] ?? '#ffffff')),
            (int) ($button_style['button_height'] ?? 34),
            (int) ($button_style['button_border_radius'] ?? 8)
        );

        wp_add_inline_style('lcni-watchlist', $css);
    }

    private function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);
        $defaults = [
            'allowed_columns' => $this->service->get_default_columns('desktop'),
            'default_columns_desktop' => $this->service->get_default_columns('desktop'),
            'default_columns_mobile' => $this->service->get_default_columns('mobile'),
            'column_labels' => [],
            'stock_detail_page_slug' => sanitize_title((string) get_option('lcni_watchlist_stock_page', '')),
            'styles' => [
                'font' => 'inherit',
                'text_color' => '#111827',
                'background' => '#ffffff',
                'border' => '1px solid #e5e7eb',
                'border_radius' => 8,
                'label_font_size' => 12,
                'row_font_size' => 13,
            ],
            'value_color_rules' => [],
            'add_button' => [
                'icon' => 'fas fa-heart',
                'background' => '#dc2626',
                'text_color' => '#ffffff',
                'font_size' => 14,
                'size' => 26,
            ],
            'add_form_button' => [
                'icon' => 'fas fa-heart',
                'background' => '#2563eb',
                'text_color' => '#ffffff',
                'font_size' => 14,
                'height' => 34,
            ],
        ];

        return wp_parse_args($saved, $defaults);
    }
}
