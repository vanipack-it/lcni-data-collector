<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Shortcodes {

    const VERSION = '1.0.0';
    const SETTINGS_META_KEY = 'lcni_stock_chart_settings';

    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_chart', [$this, 'render_fixed_chart']);
        add_shortcode('lcni_stock_chart_query', [$this, 'render_query_chart']);
        add_shortcode('lcni_stock_query_form', [$this, 'render_query_form']);
    }

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

    public function register_assets() {
        $chart_script_path = LCNI_PATH . 'assets/js/lcni-chart.js';
        $chart_script_version = file_exists($chart_script_path)
            ? (string) filemtime($chart_script_path)
            : self::VERSION;

        $sync_script_path = LCNI_PATH . 'assets/js/lcni-stock-sync.js';
        $sync_script_version = file_exists($sync_script_path)
            ? (string) filemtime($sync_script_path)
            : self::VERSION;

        wp_register_script('lcni-stock-sync', LCNI_URL . 'assets/js/lcni-stock-sync.js', [], $sync_script_version, true);
        wp_register_script('lcni-lightweight-charts', 'https://unpkg.com/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js', [], '4.2.3', true);
        wp_register_script('lcni-chart', LCNI_URL . 'assets/js/lcni-chart.js', ['lcni-lightweight-charts', 'lcni-stock-sync'], $chart_script_version, true);
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

        return rest_ensure_response($this->sanitize_user_settings(is_array($saved) ? $saved : [], $admin_config));
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

        return rest_ensure_response($settings);
    }

    public function render_fixed_chart($atts = []) {
        $atts = shortcode_atts(['symbol' => '', 'limit' => 200, 'height' => 420], $atts, 'lcni_stock_chart');
        $symbol = $this->resolve_symbol($atts['symbol']);
        if ($symbol === '') {
            return '';
        }

        return $this->render_chart_container([
            'symbol' => $symbol,
            'limit' => (int) $atts['limit'],
            'height' => (int) $atts['height'],
            'query_param' => '',
            'fallback_symbol' => '',
        ]);
    }

    public function render_query_chart($atts = []) {
        $atts = shortcode_atts(['param' => 'symbol', 'default_symbol' => '', 'limit' => 200, 'height' => 420], $atts, 'lcni_stock_chart_query');
        $query_param = sanitize_key($atts['param']);
        if ($query_param === '') {
            $query_param = 'symbol';
        }

        $query_value = isset($_GET[$query_param]) ? wp_unslash((string) $_GET[$query_param]) : '';
        $symbol = $this->sanitize_symbol($query_value);
        $fallback_symbol = $this->sanitize_symbol($atts['default_symbol']);

        return $this->render_chart_container([
            'symbol' => $symbol,
            'limit' => (int) $atts['limit'],
            'height' => (int) $atts['height'],
            'query_param' => $query_param,
            'fallback_symbol' => $fallback_symbol,
        ]);
    }

    public function render_query_form($atts = []) {
        $atts = shortcode_atts(['param' => 'symbol', 'placeholder' => 'Nhập mã cổ phiếu', 'button_text' => 'Xem chart', 'default_symbol' => ''], $atts, 'lcni_stock_query_form');
        $query_param = sanitize_key($atts['param']);
        if ($query_param === '') {
            $query_param = 'symbol';
        }

        $query_value = isset($_GET[$query_param]) ? wp_unslash((string) $_GET[$query_param]) : '';
        $symbol = $this->sanitize_symbol($query_value);
        if ($symbol === '') {
            $symbol = $this->sanitize_symbol($atts['default_symbol']);
        }

        ob_start();
        ?>
        <form method="get" class="lcni-stock-query-form" data-lcni-stock-query-form>
            <label>
                <span class="screen-reader-text"><?php echo esc_html($atts['placeholder']); ?></span>
                <input type="text" name="<?php echo esc_attr($query_param); ?>" value="<?php echo esc_attr($symbol); ?>" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" style="padding:8px 10px; min-width:160px;">
            </label>
            <button type="submit" style="padding:8px 12px;"><?php echo esc_html($atts['button_text']); ?></button>
        </form>
        <?php

        return (string) ob_get_clean();
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

    private function render_chart_container($args) {
        wp_enqueue_script('lcni-chart');

        $symbol = $this->sanitize_symbol((string) ($args['symbol'] ?? ''));
        $fallback_symbol = $this->sanitize_symbol((string) ($args['fallback_symbol'] ?? ''));
        $query_param = sanitize_key((string) ($args['query_param'] ?? ''));
        $limit = max(10, min(1000, (int) ($args['limit'] ?? 200)));
        $height = max(260, min(1000, (int) ($args['height'] ?? 420)));
        $admin_config = $this->get_admin_config();

        return sprintf(
            '<div data-lcni-chart data-api-base="%1$s" data-symbol="%2$s" data-fallback-symbol="%3$s" data-query-param="%4$s" data-limit="%5$d" data-main-height="%6$d" data-admin-config="%7$s" data-settings-api="%8$s" data-settings-nonce="%9$s" data-settings-storage-key="%10$s"></div>',
            esc_url(rest_url('lcni/v1/candles')),
            esc_attr($symbol),
            esc_attr($fallback_symbol),
            esc_attr($query_param),
            $limit,
            $height,
            esc_attr(wp_json_encode($admin_config)),
            esc_url(rest_url('lcni/v1/stock-chart/settings')),
            esc_attr(wp_create_nonce('wp_rest')),
            esc_attr('lcni_chart_settings_v1')
        );
    }

    private function get_admin_config() {
        $default = ['default_mode' => 'line', 'allowed_panels' => ['volume', 'macd', 'rsi', 'rs'], 'compact_mode' => true, 'title' => 'Stock Chart'];
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

    private function sanitize_symbol($symbol) {
        $symbol = strtoupper(sanitize_text_field((string) $symbol));
        if ($symbol === '') {
            return '';
        }

        return preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol) === 1 ? $symbol : '';
    }
}
