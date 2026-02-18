<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Shortcodes {

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
            ['lcni-lightweight-charts'],
            '1.0.0',
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

        $api_base = rest_url('lcni/v1/candles');

        return sprintf(
            '<div data-lcni-chart data-api-base="%1$s" data-symbol="%2$s" data-fallback-symbol="%3$s" data-query-param="%4$s" data-limit="%5$d" data-main-height="%6$d"></div>',
            esc_url($api_base),
            esc_attr($symbol),
            esc_attr($fallback_symbol),
            esc_attr($query_param),
            $limit,
            $height
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
