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

    private $default_styles = [
        'label_color' => '#4b5563',
        'value_color' => '#111827',
        'item_background' => '#f9fafb',
        'container_background' => '#ffffff',
        'container_border' => '#e5e7eb',
        'item_height' => 56,
        'label_font_size' => 12,
        'value_font_size' => 14,
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
        wp_register_style('lcni-stock-overview', false, [], $version);
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

        $symbol = $this->resolve_symbol($atts['symbol']);
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
        $admin_config = $this->get_admin_config();
        $allowed_fields = (array) ($admin_config['allowed_fields'] ?? $this->default_fields);
        $fields = get_user_meta(get_current_user_id(), self::SETTINGS_META_KEY, true);
        if (!is_array($fields) || empty($fields)) {
            $fields = $allowed_fields;
        }

        $fields = array_values(array_intersect($allowed_fields, array_map('sanitize_key', $fields)));
        if (empty($fields)) {
            $fields = $allowed_fields;
        }

        return rest_ensure_response([
            'fields' => $fields,
            'version' => self::VERSION,
        ]);
    }

    public function save_settings(WP_REST_Request $request) {
        $admin_config = $this->get_admin_config();
        $allowed_fields = (array) ($admin_config['allowed_fields'] ?? $this->default_fields);
        $fields = $request->get_param('fields');
        if (!is_array($fields) || empty($fields)) {
            return new WP_Error('invalid_fields', 'Danh sách fields không hợp lệ.', ['status' => 400]);
        }

        $normalized = array_values(array_intersect($allowed_fields, array_map('sanitize_key', $fields)));
        if (empty($normalized)) {
            $normalized = $allowed_fields;
        }
        update_user_meta(get_current_user_id(), self::SETTINGS_META_KEY, $normalized);

        return rest_ensure_response([
            'fields' => $normalized,
            'version' => self::VERSION,
        ]);
    }

    private function render_container($symbol, $query_param, $version) {
        wp_enqueue_script('lcni-stock-overview');
        wp_enqueue_style('lcni-stock-overview');
        LCNI_Button_Style_Config::enqueue_frontend_assets('lcni-stock-overview');
        $admin_config = $this->get_admin_config();

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

    private function get_admin_config() {
        $saved = get_option('lcni_frontend_settings_overview', []);
        $styles = isset($saved['styles']) && is_array($saved['styles']) ? $saved['styles'] : [];
        $allowed_fields = isset($saved['allowed_fields']) && is_array($saved['allowed_fields'])
            ? array_values(array_intersect($this->default_fields, array_map('sanitize_key', $saved['allowed_fields'])))
            : $this->default_fields;

        if (empty($allowed_fields)) {
            $allowed_fields = $this->default_fields;
        }

        return [
            'allowed_fields' => $allowed_fields,
            'title' => sanitize_text_field((string) get_option('lcni_frontend_overview_title', 'Stock Overview')),
            'styles' => [
                'label_color' => $this->sanitize_hex_color($styles['label_color'] ?? $this->default_styles['label_color'], $this->default_styles['label_color']),
                'value_color' => $this->sanitize_hex_color($styles['value_color'] ?? $this->default_styles['value_color'], $this->default_styles['value_color']),
                'item_background' => $this->sanitize_hex_color($styles['item_background'] ?? $this->default_styles['item_background'], $this->default_styles['item_background']),
                'container_background' => $this->sanitize_hex_color($styles['container_background'] ?? $this->default_styles['container_background'], $this->default_styles['container_background']),
                'container_border' => '1px solid ' . $this->sanitize_hex_color($styles['container_border'] ?? $this->default_styles['container_border'], $this->default_styles['container_border']),
                'item_height' => $this->sanitize_item_height($styles['item_height'] ?? $this->default_styles['item_height'], $this->default_styles['item_height']),
                'label_font_size' => $this->sanitize_font_size($styles['label_font_size'] ?? $this->default_styles['label_font_size'], $this->default_styles['label_font_size']),
                'value_font_size' => $this->sanitize_font_size($styles['value_font_size'] ?? $this->default_styles['value_font_size'], $this->default_styles['value_font_size']),
                'value_rules' => $this->sanitize_value_rules($styles['value_rules'] ?? [], $this->default_fields),
            ],
        ];
    }

    private function sanitize_value_rules($rules, $allowed_fields) {
        if (!is_array($rules)) {
            return [];
        }

        $operators = ['equals', 'contains', 'gt', 'gte', 'lt', 'lte'];
        $normalized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field = sanitize_key((string) ($rule['field'] ?? ''));
            $operator = sanitize_key((string) ($rule['operator'] ?? ''));
            $value = sanitize_text_field((string) ($rule['value'] ?? ''));
            $color = sanitize_hex_color((string) ($rule['color'] ?? ''));

            if ($field === '') {
                $field = '*';
            }

            if ($field !== '*' && !in_array($field, $allowed_fields, true)) {
                continue;
            }

            if (!in_array($operator, $operators, true) || $value === '' || !$color) {
                continue;
            }

            $normalized[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
                'color' => $color,
            ];
        }

        return array_slice($normalized, 0, 50);
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
    private function sanitize_hex_color($color, $fallback) {
        $sanitized = sanitize_hex_color((string) $color);

        return $sanitized ?: $fallback;
    }

    private function sanitize_font_size($size, $fallback) {
        $value = (int) $size;

        return $value >= 10 && $value <= 40 ? $value : (int) $fallback;
    }

    private function sanitize_item_height($height, $fallback) {
        $value = (int) $height;

        return $value >= 40 && $value <= 300 ? $value : (int) $fallback;
    }

    private function sanitize_symbol($symbol) {
        $symbol = strtoupper(sanitize_text_field((string) $symbol));
        if ($symbol === '') {
            return '';
        }

        return preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol) === 1 ? $symbol : '';
    }
}
