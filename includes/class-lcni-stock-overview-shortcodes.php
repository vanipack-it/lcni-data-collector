<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Stock_Overview_Shortcodes {

    const SETTINGS_META_KEY = 'lcni_stock_overview_fields';
    const VERSION = '1.0.0';

    private $default_fields = [
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

    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_overview', [$this, 'render_fixed']);
        add_shortcode('lcni_stock_overview_query', [$this, 'render_query']);
    }

    public function register_assets() {
        $sync_script_path = LCNI_PATH . 'assets/js/lcni-stock-sync.js';
        $sync_version = file_exists($sync_script_path) ? (string) filemtime($sync_script_path) : self::VERSION;

        wp_register_script('lcni-stock-sync', LCNI_URL . 'assets/js/lcni-stock-sync.js', [], $sync_version, true);

        $script_path = LCNI_PATH . 'assets/js/lcni-stock-overview.js';
        $version = file_exists($script_path) ? (string) filemtime($script_path) : self::VERSION;

        wp_register_script('lcni-stock-overview', LCNI_URL . 'assets/js/lcni-stock-overview.js', ['lcni-stock-sync'], $version, true);
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/stock-overview/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_settings'],
                'permission_callback' => static function () {
                    return is_user_logged_in();
                },
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_settings'],
                'permission_callback' => static function () {
                    return is_user_logged_in();
                },
            ],
        ]);
    }

    public function render_fixed($atts = []) {
        $atts = shortcode_atts([
            'symbol' => '',
            'version' => self::VERSION,
        ], $atts, 'lcni_stock_overview');

        $symbol = $this->sanitize_symbol($atts['symbol']);
        if ($symbol === '') {
            return '';
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

    public function get_settings() {
        $fields = get_user_meta(get_current_user_id(), self::SETTINGS_META_KEY, true);
        if (!is_array($fields) || empty($fields)) {
            $fields = $this->default_fields;
        }

        return rest_ensure_response([
            'fields' => array_values(array_intersect($this->default_fields, $fields)),
            'version' => self::VERSION,
        ]);
    }

    public function save_settings(WP_REST_Request $request) {
        $fields = $request->get_param('fields');
        if (!is_array($fields) || empty($fields)) {
            return new WP_Error('invalid_fields', 'Danh sách fields không hợp lệ.', ['status' => 400]);
        }

        $normalized = array_values(array_intersect($this->default_fields, array_map('sanitize_key', $fields)));
        update_user_meta(get_current_user_id(), self::SETTINGS_META_KEY, $normalized);

        return rest_ensure_response([
            'fields' => $normalized,
            'version' => self::VERSION,
        ]);
    }

    private function render_container($symbol, $query_param, $version) {
        wp_enqueue_script('lcni-stock-overview');

        return sprintf(
            '<div data-lcni-stock-overview data-symbol="%1$s" data-query-param="%2$s" data-api-base="%3$s" data-settings-api="%4$s" data-version="%5$s"></div>',
            esc_attr($symbol),
            esc_attr($query_param),
            esc_url(rest_url('lcni/v1/stock-overview')),
            esc_url(rest_url('lcni/v1/stock-overview/settings')),
            esc_attr($version)
        );
    }

    private function sanitize_symbol($symbol) {
        $symbol = strtoupper(sanitize_text_field((string) $symbol));
        if ($symbol === '') {
            return '';
        }

        return preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol) === 1 ? $symbol : '';
    }
}
