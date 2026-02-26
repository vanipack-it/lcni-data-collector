<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistShortcode {

    const OPTION_KEY = 'lcni_watchlist_settings';
    const VERSION = '2.1.7';

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

        wp_register_script('lcni-watchlist', LCNI_URL . 'modules/watchlist/assets/js/watchlist.js', ['lcni-main-js'], $version, true);
        wp_register_style('lcni-watchlist', LCNI_URL . 'modules/watchlist/assets/css/watchlist.css', [], $css_version);
    }

    public function render_watchlist() {
        $this->enqueue_watchlist_assets();

        $styles = (array) ($this->get_settings()['styles'] ?? []);
        $style_attr = sprintf(
            '--lcni-watchlist-label-font-size:%1$dpx;--lcni-watchlist-row-font-size:%2$dpx;--lcni-watchlist-header-bg:%3$s;--lcni-watchlist-header-color:%4$s;--lcni-watchlist-value-bg:%5$s;--lcni-watchlist-value-color:%6$s;--lcni-watchlist-row-divider-color:%7$s;--lcni-watchlist-row-divider-width:%8$dpx;--lcni-watchlist-row-hover-bg:%9$s;--lcni-watchlist-head-height:%10$dpx;--lcni-watchlist-dropdown-height:%11$dpx;--lcni-watchlist-dropdown-width:%12$dpx;--lcni-watchlist-dropdown-font-size:%13$dpx;--lcni-watchlist-dropdown-border-color:%14$s;--lcni-watchlist-dropdown-radius:%15$dpx;--lcni-watchlist-input-height:%16$dpx;--lcni-watchlist-input-width:%17$dpx;--lcni-watchlist-input-font-size:%18$dpx;--lcni-watchlist-input-border-color:%19$s;--lcni-watchlist-input-radius:%20$dpx;--lcni-watchlist-scroll-speed:%21$s;',
            max(10, min(30, (int) ($styles['label_font_size'] ?? 12))),
            max(10, min(30, (int) ($styles['row_font_size'] ?? 13))),
            esc_attr((string) ($styles['header_background'] ?? '#ffffff')),
            esc_attr((string) ($styles['header_text_color'] ?? '#111827')),
            esc_attr((string) ($styles['value_background'] ?? '#ffffff')),
            esc_attr((string) ($styles['value_text_color'] ?? '#111827')),
            esc_attr((string) ($styles['row_divider_color'] ?? '#e5e7eb')),
            max(1, min(6, (int) ($styles['row_divider_width'] ?? 1))),
            esc_attr((string) ($styles['row_hover_bg'] ?? '#f3f4f6')),
            max(1, min(240, (int) ($styles['head_height'] ?? 40))),
            max(28, min(80, (int) ($styles['dropdown_height'] ?? 34))),
            max(120, min(520, (int) ($styles['dropdown_width'] ?? 220))),
            max(10, min(24, (int) ($styles['dropdown_font_size'] ?? 13))),
            esc_attr((string) ($styles['dropdown_border_color'] ?? '#d1d5db')),
            max(0, min(24, (int) ($styles['dropdown_border_radius'] ?? 8))),
            max(28, min(80, (int) ($styles['input_height'] ?? 34))),
            max(120, min(520, (int) ($styles['input_width'] ?? 160))),
            max(10, min(24, (int) ($styles['input_font_size'] ?? 13))),
            esc_attr((string) ($styles['input_border_color'] ?? '#d1d5db')),
            max(0, min(24, (int) ($styles['input_border_radius'] ?? 8))),
            esc_attr((string) max(1, min(5, (int) ($styles['scroll_speed'] ?? 1))))
        );

        return sprintf('<div class="lcni-watchlist" data-lcni-watchlist style="%s"></div>', esc_attr($style_attr));
    }

    public function render_add_form() {
        $this->enqueue_watchlist_assets();
        return sprintf('<form class="lcni-watchlist-add-form" data-lcni-watchlist-add-form><input type="text" data-watchlist-symbol-input placeholder="Nhập mã cổ phiếu" autocomplete="off" /><button type="submit" class="lcni-btn lcni-btn-btn_watchlist_add_symbol">%s</button></form>', LCNI_Button_Style_Config::build_button_content('btn_watchlist_add_symbol', 'Thêm'));
    }

    public function render_add_button($atts = []) {
        $atts = shortcode_atts(['symbol' => ''], $atts, 'lcni_watchlist_add_button');
        $symbol = $this->resolve_symbol($atts['symbol']);

        if ($symbol === '') {
            return '';
        }

        $this->enqueue_watchlist_assets();
        return sprintf(
            '<button type="button" class="lcni-watchlist-add lcni-btn lcni-btn-btn_watchlist_add" data-lcni-watchlist-add data-symbol="%1$s" aria-label="Add to watchlist">%2$s</button>',
            esc_attr($symbol),
            LCNI_Button_Style_Config::build_button_content('btn_watchlist_add', '')
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

        LCNI_Button_Style_Config::enqueue_frontend_assets('lcni-watchlist');

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
            'buttonConfig' => LCNI_Button_Style_Config::get_config(),
        ]);
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
                'header_background' => '#ffffff',
                'header_text_color' => '#111827',
                'value_background' => '#ffffff',
                'value_text_color' => '#111827',
                'row_divider_color' => '#e5e7eb',
                'row_divider_width' => 1,
                'row_hover_bg' => '#f3f4f6',
                'head_height' => 40,
                'sticky_column' => 'symbol',
                'sticky_header' => 1,
                'dropdown_height' => 34,
                'dropdown_width' => 220,
                'dropdown_font_size' => 13,
                'dropdown_border_color' => '#d1d5db',
                'dropdown_border_radius' => 8,
                'input_height' => 34,
                'input_width' => 160,
                'input_font_size' => 13,
                'input_border_color' => '#d1d5db',
                'input_border_radius' => 8,
                'scroll_speed' => 1,
                'column_order' => [],
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
