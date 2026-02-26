<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Stock_Signals_Shortcodes {

    const SETTINGS_META_KEY = 'lcni_stock_signals_fields';
    const VERSION = '2.1.5';

    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_signals', [$this, 'render_fixed']);
        add_shortcode('lcni_stock_signals_query', [$this, 'render_query']);
    }

    public function register_assets() {
        $sync_script_path = LCNI_PATH . 'assets/js/lcni-stock-sync.js';
        $sync_version = file_exists($sync_script_path) ? (string) filemtime($sync_script_path) : self::VERSION;

        wp_register_script('lcni-stock-sync', LCNI_URL . 'assets/js/lcni-stock-sync.js', [], $sync_version, true);

        $script_path = LCNI_PATH . 'assets/js/lcni-stock-signals.js';
        $version = file_exists($script_path) ? (string) filemtime($script_path) : self::VERSION;

        wp_register_script('lcni-stock-signals', LCNI_URL . 'assets/js/lcni-stock-signals.js', ['lcni-stock-sync'], $version, true);
        wp_register_style('lcni-stock-signals', false, [], $version);
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/stock-signals/settings', [
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
        ], $atts, 'lcni_stock_signals');

        $symbol = lcni_get_current_symbol($atts['symbol']);
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
        ], $atts, 'lcni_stock_signals_query');

        $param = sanitize_key((string) $atts['param']);
        if ($param === '') {
            $param = 'symbol';
        }

        $symbol = lcni_get_current_symbol($atts['default_symbol']);

        return $this->render_container($symbol, $param, (string) $atts['version']);
    }

    private function render_container($symbol, $query_param, $version) {
        wp_enqueue_script('lcni-stock-signals');
        wp_enqueue_style('lcni-stock-signals');
        LCNI_Button_Style_Config::enqueue_frontend_assets('lcni-stock-signals');
        $admin_config = $this->get_admin_config();

        return sprintf(
            '<div data-lcni-stock-signals data-symbol="%1$s" data-query-param="%2$s" data-api-base="%3$s" data-settings-api="%4$s" data-admin-config="%5$s" data-button-config="%6$s" data-version="%7$s"></div>',
            esc_attr($symbol),
            esc_attr($query_param),
            esc_url(rest_url('lcni/v1/stock-signals')),
            esc_url(rest_url('lcni/v1/stock-signals/settings')),
            esc_attr(wp_json_encode($admin_config)),
            esc_attr(wp_json_encode(LCNI_Button_Style_Config::get_button('btn_signals_setting'))),
            esc_attr($version)
        );
    }

    public function get_settings() {
        $admin_config = $this->get_admin_config();
        $allowed_fields = (array) ($admin_config['allowed_fields'] ?? []);
        $fields = get_user_meta(get_current_user_id(), self::SETTINGS_META_KEY, true);

        if (!is_array($fields) || empty($fields)) {
            $fields = $allowed_fields;
        }

        $fields = array_values(array_intersect($allowed_fields, array_map('sanitize_key', $fields)));
        if (empty($fields)) {
            $fields = $allowed_fields;
        }

        wp_send_json_success([
            'fields' => $fields,
            'version' => self::VERSION,
        ]);
    }

    public function save_settings(WP_REST_Request $request) {
        $admin_config = $this->get_admin_config();
        $allowed_fields = (array) ($admin_config['allowed_fields'] ?? []);
        $fields = $request->get_param('fields');

        if (!is_array($fields)) {
            return new WP_Error('invalid_fields', 'Danh sách fields không hợp lệ.', ['status' => 400]);
        }

        $normalized = array_values(array_intersect($allowed_fields, array_map('sanitize_key', $fields)));
        if (empty($normalized)) {
            $normalized = $allowed_fields;
        }

        update_user_meta(get_current_user_id(), self::SETTINGS_META_KEY, $normalized);

        wp_send_json_success([
            'fields' => $normalized,
            'version' => self::VERSION,
        ]);
    }
    private function get_admin_config() {
        $default = $this->get_default_admin_config();
        $saved = get_option('lcni_frontend_settings_signals', []);

        if (!is_array($saved)) {
            return $default;
        }

        $allowed_fields = isset($saved['allowed_fields']) && is_array($saved['allowed_fields'])
            ? array_values(array_intersect($default['allowed_fields'], array_map('sanitize_key', $saved['allowed_fields'])))
            : $default['allowed_fields'];

        if (empty($allowed_fields)) {
            $allowed_fields = $default['allowed_fields'];
        }

        $styles = isset($saved['styles']) && is_array($saved['styles']) ? $saved['styles'] : [];

        return [
            'allowed_fields' => $allowed_fields,
            'field_labels' => $default['field_labels'],
            'title' => sanitize_text_field((string) get_option('lcni_frontend_signal_title', 'LCNi Signals')),
            'styles' => [
                'label_color' => $this->sanitize_hex_color($styles['label_color'] ?? $default['styles']['label_color'], $default['styles']['label_color']),
                'value_color' => $this->sanitize_hex_color($styles['value_color'] ?? $default['styles']['value_color'], $default['styles']['value_color']),
                'item_background' => $this->sanitize_hex_color($styles['item_background'] ?? $default['styles']['item_background'], $default['styles']['item_background']),
                'container_background' => $this->sanitize_hex_color($styles['container_background'] ?? $default['styles']['container_background'], $default['styles']['container_background']),
                'container_border' => '1px solid ' . $this->sanitize_hex_color($styles['container_border'] ?? $default['styles']['container_border'], $default['styles']['container_border']),
                'item_height' => $this->sanitize_item_height($styles['item_height'] ?? $default['styles']['item_height'], $default['styles']['item_height']),
                'label_font_size' => $this->sanitize_font_size($styles['label_font_size'] ?? $default['styles']['label_font_size'], $default['styles']['label_font_size']),
                'value_font_size' => $this->sanitize_font_size($styles['value_font_size'] ?? $default['styles']['value_font_size'], $default['styles']['value_font_size']),
                'value_rules' => $this->sanitize_value_rules($styles['value_rules'] ?? [], $default['allowed_fields']),
            ],
        ];
    }

    private function get_default_admin_config() {
        $field_labels = $this->get_signal_field_labels();

        return [
            'allowed_fields' => array_keys($field_labels),
            'field_labels' => $field_labels,
            'styles' => [
                'label_color' => '#4b5563',
                'value_color' => '#111827',
                'item_background' => '#f9fafb',
                'container_background' => '#ffffff',
                'container_border' => '#e5e7eb',
                'item_height' => 56,
                'label_font_size' => 12,
                'value_font_size' => 14,
                'value_rules' => [],
            ],
        ];
    }

    private function get_signal_field_labels() {
        global $wpdb;

        $labels = [
            'xay_nen' => 'Nền giá',
            'xay_nen_count_30' => 'Số phiên đi nền trong 30 phiên',
            'nen_type' => 'Dạng nền',
            'pha_nen' => 'Tín hiệu phá nền',
            'tang_gia_kem_vol' => 'Tăng giá kèm Vol',
            'smart_money' => 'Tín hiệu smart',
            'rs_exchange_status' => 'Trạng thái sức mạnh giá',
            'rs_exchange_recommend' => 'Gợi ý sức mạnh giá',
            'rs_recommend_status' => 'Gợi ý trạng thái sức mạnh giá',
        ];

        if (!isset($wpdb) || !($wpdb instanceof wpdb)) {
            return $labels;
        }

        $table = $wpdb->prefix . 'lcni_ohlc_latest';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return $labels;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!is_array($columns) || empty($columns)) {
            return $labels;
        }

        foreach ($columns as $column) {
            $field = sanitize_key((string) $column);
            if ($field === '' || isset($labels[$field])) {
                continue;
            }

            $labels[$field] = ucwords(str_replace('_', ' ', $field));
        }

        return $labels;
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
