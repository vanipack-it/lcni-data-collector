<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Shortcodes {

    const VERSION = '1.0.0';

    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_chart', [$this, 'render_fixed_chart']);
        add_shortcode('lcni_stock_chart_query', [$this, 'render_query_chart']);
        add_shortcode('lcni_stock_query_form', [$this, 'render_query_form']);
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

        wp_register_script(
            'lcni-stock-sync',
            LCNI_URL . 'assets/js/lcni-stock-sync.js',
            [],
            $sync_script_version,
            true
        );

        wp_register_script(
            'lcni-lightweight-charts',
            'https://unpkg.com/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js',
            [],
            '4.2.3',
            true
        );

        wp_register_script(
            'lcni-chart',
            LCNI_URL . 'assets/js/lcni-chart.js',
            ['lcni-lightweight-charts', 'lcni-stock-sync'],
            $chart_script_version,
            true
        );
    }

    public function render_fixed_chart($atts = []) {
        $atts = shortcode_atts([
            'symbol' => '',
            'limit' => 200,
            'height' => 420,
        ], $atts, 'lcni_stock_chart');

        $symbol = $this->sanitize_symbol($atts['symbol']);
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
        $atts = shortcode_atts([
            'param' => 'symbol',
            'default_symbol' => '',
            'limit' => 200,
            'height' => 420,
        ], $atts, 'lcni_stock_chart_query');

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
        $atts = shortcode_atts([
            'param' => 'symbol',
            'placeholder' => 'Nhập mã cổ phiếu',
            'button_text' => 'Xem chart',
            'default_symbol' => '',
        ], $atts, 'lcni_stock_query_form');

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
                <input
                    type="text"
                    name="<?php echo esc_attr($query_param); ?>"
                    value="<?php echo esc_attr($symbol); ?>"
                    placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                    style="padding:8px 10px; min-width:160px;"
                >
            </label>
            <button type="submit" style="padding:8px 12px;"><?php echo esc_html($atts['button_text']); ?></button>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    private function render_chart_container($args) {
        wp_enqueue_script('lcni-chart');

        $symbol = $this->sanitize_symbol((string) ($args['symbol'] ?? ''));
        $fallback_symbol = $this->sanitize_symbol((string) ($args['fallback_symbol'] ?? ''));
        $query_param = sanitize_key((string) ($args['query_param'] ?? ''));
        $limit = max(10, min(1000, (int) ($args['limit'] ?? 200)));
        $height = max(260, min(1000, (int) ($args['height'] ?? 420)));
        $admin_config = $this->get_admin_config();

        $api_base = rest_url('lcni/v1/candles');

        return sprintf(
            '<div data-lcni-chart data-api-base="%1$s" data-symbol="%2$s" data-fallback-symbol="%3$s" data-query-param="%4$s" data-limit="%5$d" data-main-height="%6$d" data-admin-config="%7$s"></div>',
            esc_url($api_base),
            esc_attr($symbol),
            esc_attr($fallback_symbol),
            esc_attr($query_param),
            $limit,
            $height,
            esc_attr(wp_json_encode($admin_config))
        );
    }

    private function get_admin_config() {
        $default = [
            'default_mode' => 'line',
            'allowed_panels' => ['volume', 'macd', 'rsi', 'rs'],
            'compact_mode' => true,
        ];

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
        ];
    }

    private function sanitize_symbol($symbol) {
        $symbol = strtoupper(sanitize_text_field((string) $symbol));
        if ($symbol === '') {
            return '';
        }

        return preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol) === 1 ? $symbol : '';
    }
}
