<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Overview_Ajax {

    const SETTINGS_META_KEY = 'lcni_stock_overview_fields';

    private $default_fields;

    public function __construct(array $default_fields) {
        $this->default_fields = $default_fields;
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
            'version' => LCNI_Overview_Shortcode::VERSION,
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
            'version' => LCNI_Overview_Shortcode::VERSION,
        ]);
    }

    public function get_admin_config() {
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
                'label_color' => $this->sanitize_hex_color($styles['label_color'] ?? LCNI_Overview_Shortcode::DEFAULT_STYLES['label_color'], LCNI_Overview_Shortcode::DEFAULT_STYLES['label_color']),
                'value_color' => $this->sanitize_hex_color($styles['value_color'] ?? LCNI_Overview_Shortcode::DEFAULT_STYLES['value_color'], LCNI_Overview_Shortcode::DEFAULT_STYLES['value_color']),
                'item_background' => $this->sanitize_hex_color($styles['item_background'] ?? LCNI_Overview_Shortcode::DEFAULT_STYLES['item_background'], LCNI_Overview_Shortcode::DEFAULT_STYLES['item_background']),
                'container_background' => $this->sanitize_hex_color($styles['container_background'] ?? LCNI_Overview_Shortcode::DEFAULT_STYLES['container_background'], LCNI_Overview_Shortcode::DEFAULT_STYLES['container_background']),
                'container_border' => '1px solid ' . $this->sanitize_hex_color($styles['container_border'] ?? LCNI_Overview_Shortcode::DEFAULT_STYLES['container_border'], LCNI_Overview_Shortcode::DEFAULT_STYLES['container_border']),
                'item_height' => $this->sanitize_item_height($styles['item_height'] ?? LCNI_Overview_Shortcode::DEFAULT_STYLES['item_height'], LCNI_Overview_Shortcode::DEFAULT_STYLES['item_height']),
                'label_font_size' => $this->sanitize_font_size($styles['label_font_size'] ?? LCNI_Overview_Shortcode::DEFAULT_STYLES['label_font_size'], LCNI_Overview_Shortcode::DEFAULT_STYLES['label_font_size']),
                'value_font_size' => $this->sanitize_font_size($styles['value_font_size'] ?? LCNI_Overview_Shortcode::DEFAULT_STYLES['value_font_size'], LCNI_Overview_Shortcode::DEFAULT_STYLES['value_font_size']),
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
}
