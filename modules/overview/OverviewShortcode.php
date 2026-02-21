<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Overview_Shortcode {

    const VERSION = '1.0.0';
    const DEFAULT_FIELDS = [
        'symbol',
        'exchange',
        'icb2_name',
        'eps',
        'eps_1y_pct',
        'dt_1y_pct',
        'bien_ln_gop',
        'bien_ln_rong',
        'roe',
        'de_ratio',
        'pe_ratio',
        'pb_ratio',
        'ev_ebitda',
        'tcbs_khuyen_nghi',
        'co_tuc_pct',
        'tc_rating',
        'so_huu_nn_pct',
        'tien_mat_rong_von_hoa',
        'tien_mat_rong_tong_tai_san',
        'loi_nhuan_4_quy_gan_nhat',
        'tang_truong_dt_quy_gan_nhat',
        'tang_truong_dt_quy_gan_nhi',
        'tang_truong_ln_quy_gan_nhat',
        'tang_truong_ln_quy_gan_nhi',
    ];

    const DEFAULT_STYLES = [
        'label_color' => '#4b5563',
        'value_color' => '#111827',
        'item_background' => '#f9fafb',
        'container_background' => '#ffffff',
        'container_border' => '#e5e7eb',
        'item_height' => 56,
        'label_font_size' => 12,
        'value_font_size' => 14,
    ];

    private $ajax;

    public function __construct() {
        $this->ajax = new LCNI_Overview_Ajax(self::DEFAULT_FIELDS);

        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('rest_api_init', [$this->ajax, 'register_routes']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_overview', [$this, 'render']);
        add_shortcode('lcni_stock_overview_query', [$this, 'render_query']);
    }

    public function register_assets() {
        $sync_script_path = LCNI_PATH . 'assets/js/lcni-stock-sync.js';
        $sync_version = file_exists($sync_script_path) ? (string) filemtime($sync_script_path) : self::VERSION;

        wp_register_script('lcni-stock-sync', LCNI_URL . 'assets/js/lcni-stock-sync.js', [], $sync_version, true);

        $script_path = LCNI_PATH . 'modules/overview/assets/overview.js';
        $version = file_exists($script_path) ? (string) filemtime($script_path) : self::VERSION;

        wp_register_script('lcni-stock-overview', LCNI_URL . 'modules/overview/assets/overview.js', ['lcni-stock-sync'], $version, true);
        wp_register_style('lcni-stock-overview', LCNI_URL . 'modules/overview/assets/overview.css', [], $version);
    }

    public function render($atts = []) {
        $atts = shortcode_atts([
            'symbol' => '',
            'version' => self::VERSION,
        ], $atts, 'lcni_stock_overview');

        $symbol = $this->resolve_symbol($atts['symbol']);
        if ($symbol === '') {
            return $this->render_missing_symbol_notice();
        }

        return $this->render_container($symbol, '', (string) $atts['version']);
    }

    public function render_query($atts = []) {
        $atts = shortcode_atts([
            'param' => 'symbol',
            'default_symbol' => '',
            'version' => self::VERSION,
        ], $atts, 'lcni_stock_overview_query');

        $param = sanitize_key((string) $atts['param']);
        $query_symbol = isset($_GET[$param]) ? wp_unslash((string) $_GET[$param]) : '';
        $symbol = $this->sanitize_symbol($query_symbol);
        if ($symbol === '') {
            $symbol = $this->sanitize_symbol($atts['default_symbol']);
        }

        if ($symbol === '') {
            return '';
        }

        return $this->render_container($symbol, $param, (string) $atts['version']);
    }

    private function render_container($symbol, $query_param, $version) {
        wp_enqueue_script('lcni-stock-overview');
        wp_enqueue_style('lcni-stock-overview');
        LCNI_Button_Style_Config::enqueue_frontend_assets('lcni-stock-overview');
        $admin_config = $this->ajax->get_admin_config();

        return sprintf(
            '<div data-lcni-stock-overview data-symbol="%1$s" data-query-param="%2$s" data-api-base="%3$s" data-settings-api="%4$s" data-admin-config="%5$s" data-button-config="%6$s" data-version="%7$s"></div>',
            esc_attr($symbol),
            esc_attr($query_param),
            esc_url(rest_url('lcni/v1/stock-overview')),
            esc_url(rest_url('lcni/v1/stock-overview/settings')),
            esc_attr(wp_json_encode($admin_config)),
            esc_attr(wp_json_encode(LCNI_Button_Style_Config::get_button('btn_overview_setting'))),
            esc_attr($version)
        );
    }

    private function render_missing_symbol_notice() {
        wp_enqueue_style('lcni-stock-overview');
        wp_add_inline_style('lcni-stock-overview', '.lcni-module-empty{padding:24px;text-align:center;background:#fafafa;border:1px solid #eee;border-radius:6px;}.lcni-module-empty-inner{color:#777;font-size:14px;}');

        return '<div class="lcni-module-empty"><div class="lcni-module-empty-inner">Vui lòng chọn mã cổ phiếu để xem dữ liệu.</div></div>';
    }

    private function resolve_symbol($symbol) {
        $normalized = $this->sanitize_symbol($symbol);
        if ($normalized !== '') {
            return $normalized;
        }

        $query_symbol = get_query_var('symbol');
        if (!is_string($query_symbol) || $query_symbol === '') {
            $query_symbol = get_query_var('lcni_stock_symbol');
        }

        return $this->sanitize_symbol((string) $query_symbol);
    }

    private function sanitize_symbol($symbol) {
        $symbol = strtoupper(sanitize_text_field((string) $symbol));
        if ($symbol === '') {
            return '';
        }

        return preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol) === 1 ? $symbol : '';
    }
}

if (!class_exists('LCNI_Stock_Overview_Shortcodes')) {
    class LCNI_Stock_Overview_Shortcodes extends LCNI_Overview_Shortcode {
    }
}
