<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Heatmap_Shortcode {

    const VERSION = '1.0.0';

    private $watchlist_service;
    private $saas_service;

    public function __construct(LCNI_WatchlistService $watchlist_service, ?LCNI_SaaS_Service $saas_service = null) {
        $this->watchlist_service = $watchlist_service;
        $this->saas_service      = $saas_service;
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_enqueue_scripts', [$this, 'conditionally_enqueue_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_heatmap', [$this, 'render']);
    }

    public function register_assets() {
        $js_path  = LCNI_PATH . 'modules/heatmap/assets/heatmap.js';
        $css_path = LCNI_PATH . 'modules/heatmap/assets/heatmap.css';
        $js_ver   = file_exists($js_path)  ? (string) filemtime($js_path)  : self::VERSION;
        $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : self::VERSION;

        wp_register_script('lcni-heatmap', LCNI_URL . 'modules/heatmap/assets/heatmap.js', ['lcni-main-js'], $js_ver, true);
        wp_register_style('lcni-heatmap',  LCNI_URL . 'modules/heatmap/assets/heatmap.css', [], $css_ver);
    }

    public function conditionally_enqueue_assets() {
        if (!$this->should_enqueue()) {
            return;
        }

        wp_enqueue_script('lcni-heatmap');
        wp_enqueue_style('lcni-heatmap');

        $stock_page_slug = sanitize_title((string) get_option('lcni_watchlist_stock_page', ''));
        $stock_detail_url = $stock_page_slug !== '' ? home_url('/' . $stock_page_slug . '/') : '';

        $settings = $this->get_display_settings();

        wp_localize_script('lcni-heatmap', 'lcniHeatmapConfig', [
            'restUrl'        => esc_url_raw(rest_url('lcni/v1/heatmap/data')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'stockDetailUrl' => esc_url_raw($stock_detail_url),
            'settings'       => $settings,
        ]);
    }

    public function render($atts) {
        $atts = shortcode_atts([
            'height'    => '',   // e.g. "400px"
            'min_height' => '300px',
        ], $atts, 'lcni_heatmap');

        $style = '';
        $h     = sanitize_text_field($atts['height']);
        $mh    = sanitize_text_field($atts['min_height']);
        if ($h !== '') {
            $style .= 'height:' . esc_attr($h) . ';';
        }
        if ($mh !== '') {
            $style .= 'min-height:' . esc_attr($mh) . ';';
        }

        return '<div class="lcni-app"><div class="lcni-heatmap-root" data-lcni-heatmap' . ($style ? ' style="' . $style . '"' : '') . '></div></div>';
    }

    // ─── private ─────────────────────────────────────────────────────────────

    private function should_enqueue(): bool {
        if (!is_singular()) {
            return false;
        }
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return false;
        }
        return has_shortcode((string) $post->post_content, 'lcni_heatmap');
    }

    private function get_display_settings(): array {
        $raw = get_option('lcni_heatmap_display_settings', []);
        $raw = is_array($raw) ? $raw : [];

        return [
            'label_font_size'   => max(10, min(32, (int) ($raw['label_font_size']   ?? 13))),
            'count_font_size'   => max(16, min(80, (int) ($raw['count_font_size']   ?? 42))),
            'symbol_font_size'  => max(9,  min(24, (int) ($raw['symbol_font_size']  ?? 13))),
            'gap'               => max(2, min(20, (int) ($raw['gap']            ?? 6))),
            'border_radius'     => max(0, min(24, (int) ($raw['border_radius']  ?? 8))),
        ];
    }
}
