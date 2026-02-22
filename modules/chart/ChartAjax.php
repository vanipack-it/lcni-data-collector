<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Ajax {

    const SETTINGS_META_KEY = 'lcni_stock_chart_settings';

    public function register_routes() {
        register_rest_route('lcni/v1', '/stock-chart/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_user_settings'],
                'permission_callback' => static function () {
                    return is_user_logged_in();
                },
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_user_settings'],
                'permission_callback' => static function () {
                    return is_user_logged_in();
                },
            ],
        ]);
    }

    public function get_user_settings(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) {
            return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        }

        $admin_config = $this->get_admin_config();
        $saved = get_user_meta(get_current_user_id(), self::SETTINGS_META_KEY, true);
        if (is_string($saved) && $saved !== '') {
            $decoded = json_decode($saved, true);
            if (is_array($decoded)) {
                $saved = $decoded;
            }
        }

        wp_send_json_success($this->sanitize_user_settings(is_array($saved) ? $saved : [], $admin_config));
    }

    public function save_user_settings(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) {
            return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        }

        $admin_config = $this->get_admin_config();
        $settings = $this->sanitize_user_settings([
            'mode' => $request->get_param('mode'),
            'panels' => $request->get_param('panels'),
        ], $admin_config);

        update_user_meta(get_current_user_id(), self::SETTINGS_META_KEY, wp_json_encode($settings));

        wp_send_json_success($settings);
    }

    public function get_admin_config() {
        $default = ['default_mode' => 'line', 'allowed_panels' => ['volume', 'macd', 'rsi', 'rs'], 'compact_mode' => true, 'default_visible_bars' => 120, 'chart_sync_enabled' => true, 'fit_to_screen_on_load' => true, 'title' => 'Stock Chart'];
        $saved = get_option('lcni_frontend_settings_chart', []);
        if (!is_array($saved)) {
            return $default;
        }

        $allowed_panels = isset($saved['allowed_panels']) && is_array($saved['allowed_panels'])
            ? array_values(array_intersect($default['allowed_panels'], array_map('sanitize_key', $saved['allowed_panels'])))
            : $default['allowed_panels'];
        if (empty($allowed_panels)) {
            $allowed_panels = $default['allowed_panels'];
        }

        $mode = sanitize_key((string) ($saved['default_mode'] ?? $default['default_mode']));
        if (!in_array($mode, ['line', 'candlestick'], true)) {
            $mode = $default['default_mode'];
        }

        return [
            'default_mode' => $mode,
            'allowed_panels' => $allowed_panels,
            'compact_mode' => isset($saved['compact_mode']) ? (bool) $saved['compact_mode'] : $default['compact_mode'],
            'default_visible_bars' => max(20, min(1000, (int) ($saved['default_visible_bars'] ?? $default['default_visible_bars']))),
            'chart_sync_enabled' => !array_key_exists('chart_sync_enabled', $saved) || (bool) $saved['chart_sync_enabled'],
            'fit_to_screen_on_load' => !array_key_exists('fit_to_screen_on_load', $saved) || (bool) $saved['fit_to_screen_on_load'],
            'title' => sanitize_text_field((string) get_option('lcni_frontend_chart_title', $default['title'])),
        ];
    }

    private function sanitize_user_settings($payload, $admin_config) {
        $allowed_panels = isset($admin_config['allowed_panels']) && is_array($admin_config['allowed_panels'])
            ? array_values(array_map('sanitize_key', $admin_config['allowed_panels']))
            : ['volume', 'macd', 'rsi', 'rs'];

        $mode = sanitize_key((string) ($payload['mode'] ?? ($admin_config['default_mode'] ?? 'line')));
        if (!in_array($mode, ['line', 'candlestick'], true)) {
            $mode = $admin_config['default_mode'] ?? 'line';
        }

        $panels = isset($payload['panels']) && is_array($payload['panels'])
            ? array_values(array_intersect($allowed_panels, array_map('sanitize_key', $payload['panels'])))
            : $allowed_panels;

        if (empty($panels)) {
            $panels = $allowed_panels;
        }

        return ['mode' => $mode, 'panels' => $panels];
    }

    private function verify_rest_nonce(WP_REST_Request $request) {
        $nonce = $request->get_header('x_wp_nonce');

        return is_string($nonce) && wp_verify_nonce($nonce, 'wp_rest');
    }
}
