<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Ajax {

    const SETTINGS_META_KEY = 'lcni_stock_chart_settings';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/chart', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_chart'],
            'permission_callback' => '__return_true',
        ]);

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

    public function handle_chart(WP_REST_Request $request) {
        $symbol = strtoupper(sanitize_text_field((string) $request->get_param('symbol')));
        $limit = min(max(absint($request->get_param('limit')), 1), 500);

        if ($symbol === '') {
            return new WP_Error('invalid_symbol', 'Invalid symbol', ['status' => 400]);
        }

        $payload = LCNI_API::get_candles($symbol, '1D', max($limit * 2, 30));

        if ($payload === false) {
            return new WP_Error(
                'chart_data_unavailable',
                LCNI_API::get_last_request_error() ?: 'Unable to fetch chart data',
                ['status' => 502]
            );
        }

        $candles = $this->normalize_candles($payload);
        if ($limit > 0 && count($candles) > $limit) {
            $candles = array_slice($candles, -$limit);
        }

        return rest_ensure_response([
            'symbol' => $symbol,
            'data' => $candles,
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
        $default = ['default_mode' => 'line', 'allowed_panels' => ['volume', 'macd', 'rsi', 'rs'], 'compact_mode' => true, 'default_visible_bars' => 120, 'chart_sync_enabled' => true, 'fit_to_screen_on_load' => true, 'title' => 'Stock Chart', 'default_indicators' => ['ma20' => true, 'ma50' => true, 'ma100' => false, 'ma200' => false, 'rsi' => true, 'macd' => false, 'rs_1w_by_exchange' => true, 'rs_1m_by_exchange' => true, 'rs_3m_by_exchange' => false]];
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
            'default_indicators' => [
                'ma20' => !array_key_exists('default_ma20', $saved) || (bool) $saved['default_ma20'],
                'ma50' => !array_key_exists('default_ma50', $saved) || (bool) $saved['default_ma50'],
                'ma100' => !empty($saved['default_ma100']),
                'ma200' => !empty($saved['default_ma200']),
                'rsi' => !array_key_exists('default_rsi', $saved) || (bool) $saved['default_rsi'],
                'macd' => !empty($saved['default_macd']),
                'rs_1w_by_exchange' => !array_key_exists('default_rs_1w_by_exchange', $saved) || (bool) $saved['default_rs_1w_by_exchange'],
                'rs_1m_by_exchange' => !array_key_exists('default_rs_1m_by_exchange', $saved) || (bool) $saved['default_rs_1m_by_exchange'],
                'rs_3m_by_exchange' => !empty($saved['default_rs_3m_by_exchange']),
            ],
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

    private function normalize_candles($payload) {
        $timestamps = isset($payload['t']) && is_array($payload['t']) ? $payload['t'] : [];
        $opens = isset($payload['o']) && is_array($payload['o']) ? $payload['o'] : [];
        $highs = isset($payload['h']) && is_array($payload['h']) ? $payload['h'] : [];
        $lows = isset($payload['l']) && is_array($payload['l']) ? $payload['l'] : [];
        $closes = isset($payload['c']) && is_array($payload['c']) ? $payload['c'] : [];
        $volumes = isset($payload['v']) && is_array($payload['v']) ? $payload['v'] : [];
        $rs1wValues = isset($payload['rs_1w_by_exchange']) && is_array($payload['rs_1w_by_exchange']) ? $payload['rs_1w_by_exchange'] : [];
        $rs1mValues = isset($payload['rs_1m_by_exchange']) && is_array($payload['rs_1m_by_exchange']) ? $payload['rs_1m_by_exchange'] : [];
        $rs3mValues = isset($payload['rs_3m_by_exchange']) && is_array($payload['rs_3m_by_exchange']) ? $payload['rs_3m_by_exchange'] : [];

        $count = min(count($timestamps), count($opens), count($highs), count($lows), count($closes));
        if ($count <= 0) {
            return [];
        }

        $candles = [];
        for ($i = 0; $i < $count; $i++) {
            $timestamp = absint($timestamps[$i]);
            if ($timestamp <= 0) {
                continue;
            }

            $open = (float) $opens[$i];
            $high = (float) $highs[$i];
            $low = (float) $lows[$i];
            $close = (float) $closes[$i];
            $volume = isset($volumes[$i]) ? (int) $volumes[$i] : 0;

            $candles[] = [
                'date' => gmdate('Y-m-d', $timestamp),
                'timestamp' => $timestamp,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => max(0, $volume),
                'rs_1w_by_exchange' => isset($rs1wValues[$i]) && is_numeric($rs1wValues[$i]) ? (float) $rs1wValues[$i] : null,
                'rs_1m_by_exchange' => isset($rs1mValues[$i]) && is_numeric($rs1mValues[$i]) ? (float) $rs1mValues[$i] : null,
                'rs_3m_by_exchange' => isset($rs3mValues[$i]) && is_numeric($rs3mValues[$i]) ? (float) $rs3mValues[$i] : null,
            ];
        }

        return $candles;
    }
}
