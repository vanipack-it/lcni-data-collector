<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Shortcode {

    const VERSION = '1.0.0';

    private $ajax;

    public function __construct() {
        $this->ajax = new LCNI_Chart_Ajax();

        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('rest_api_init', [$this->ajax, 'register_routes']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_chart', [$this, 'render']);
        add_shortcode('lcni_stock_chart_query', [$this, 'render_query_chart']);
        add_shortcode('lcni_stock_query_form', [$this, 'render_query_form']);
    }

    public function register_assets() {
        $chart_script_path = LCNI_PATH . 'modules/chart/assets/chart.js';
        $chart_script_version = file_exists($chart_script_path)
            ? (string) filemtime($chart_script_path)
            : self::VERSION;

        $sync_script_path = LCNI_PATH . 'assets/js/lcni-stock-sync.js';
        $sync_script_version = file_exists($sync_script_path)
            ? (string) filemtime($sync_script_path)
            : self::VERSION;

        $engine_script_path = LCNI_PATH . 'assets/js/lcni-echarts-engine.js';
        $engine_script_version = file_exists($engine_script_path)
            ? (string) filemtime($engine_script_path)
            : self::VERSION;

        $echarts_vendor_path = LCNI_PATH . 'assets/vendor/echarts.min.js';
        $echarts_vendor_version = file_exists($echarts_vendor_path)
            ? (string) filemtime($echarts_vendor_path)
            : self::VERSION;

        $chart_style_path = LCNI_PATH . 'modules/chart/assets/chart.css';
        $chart_style_version = file_exists($chart_style_path)
            ? (string) filemtime($chart_style_path)
            : self::VERSION;

        wp_register_script('lcni-stock-sync', LCNI_URL . 'assets/js/lcni-stock-sync.js', [], $sync_script_version, true);
        wp_register_script('lcni-lightweight-charts', 'https://unpkg.com/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js', [], '4.2.3', true);
        wp_register_script('lcni-echarts-vendor', LCNI_URL . 'assets/vendor/echarts.min.js', [], $echarts_vendor_version, true);
        wp_register_script('lcni-echarts-engine', LCNI_URL . 'assets/js/lcni-echarts-engine.js', ['lcni-echarts-vendor'], $engine_script_version, true);
        wp_register_script('lcni-chart', LCNI_URL . 'modules/chart/assets/chart.js', ['lcni-stock-sync', 'lcni-echarts-engine', 'lcni-lightweight-charts'], $chart_script_version, true);
        wp_register_style('lcni-chart-ui', LCNI_URL . 'modules/chart/assets/chart.css', [], $chart_style_version);
    }

    public function render($atts = []) {
        $atts = shortcode_atts(['symbol' => '', 'limit' => 200, 'height' => 420], $atts, 'lcni_stock_chart');
        $symbol = $this->resolve_symbol($atts['symbol']);
        if ($symbol === '') {
            return $this->render_missing_symbol_notice();
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

        wp_enqueue_style('lcni-chart-ui');
        LCNI_Button_Style_Config::enqueue_frontend_assets('lcni-chart-ui');

        ob_start();
        ?>
        <form method="get" class="lcni-stock-query-form" data-lcni-stock-query-form>
            <label>
                <span class="screen-reader-text"><?php echo esc_html($atts['placeholder']); ?></span>
                <input type="text" name="<?php echo esc_attr($query_param); ?>" value="<?php echo esc_attr($symbol); ?>" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" style="padding:8px 10px; min-width:160px;">
            </label>
            <button type="submit" class="lcni-btn lcni-btn-btn_stock_view" style="padding:8px 12px;"><?php echo LCNI_Button_Style_Config::build_button_content('btn_stock_view', (string) $atts['button_text']); ?></button>
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

    private function render_missing_symbol_notice() {
        wp_enqueue_style('lcni-chart-ui');
        wp_add_inline_style('lcni-chart-ui', '.lcni-module-empty{padding:24px;text-align:center;background:#fafafa;border:1px solid #eee;border-radius:6px;}.lcni-module-empty-inner{color:#777;font-size:14px;}');

        return '<div class="lcni-module-empty"><div class="lcni-module-empty-inner">Vui lòng chọn mã cổ phiếu để xem dữ liệu.</div></div>';
    }

    private function render_chart_container($args) {
        wp_enqueue_script('lcni-chart');
        wp_add_inline_script('lcni-chart', 'window.lcniChartEngineType = ' . wp_json_encode((string) get_option('lcni_chart_engine_type', 'echarts')) . ';', 'before');
        wp_enqueue_style('lcni-chart-ui');
        LCNI_Button_Style_Config::enqueue_frontend_assets('lcni-chart-ui');

        $symbol = $this->sanitize_symbol((string) ($args['symbol'] ?? ''));
        $fallback_symbol = $this->sanitize_symbol((string) ($args['fallback_symbol'] ?? ''));
        $query_param = sanitize_key((string) ($args['query_param'] ?? ''));
        $limit = max(10, min(1000, (int) ($args['limit'] ?? 200)));
        $height = max(260, min(1000, (int) ($args['height'] ?? 420)));
        $admin_config = $this->ajax->get_admin_config();

        return sprintf(
            '<div data-lcni-chart data-api-base="%1$s" data-symbol="%2$s" data-fallback-symbol="%3$s" data-query-param="%4$s" data-limit="%5$d" data-main-height="%6$d" data-admin-config="%7$s" data-settings-api="%8$s" data-settings-nonce="%9$s" data-settings-storage-key="%10$s" data-button-config="%11$s"></div>',
            esc_url(rest_url('lcni/v1/candles')),
            esc_attr($symbol),
            esc_attr($fallback_symbol),
            esc_attr($query_param),
            $limit,
            $height,
            esc_attr(wp_json_encode($admin_config)),
            esc_url(rest_url('lcni/v1/stock-chart/settings')),
            esc_attr(wp_create_nonce('wp_rest')),
            esc_attr('lcni_chart_settings_v1'),
            esc_attr(wp_json_encode(LCNI_Button_Style_Config::get_button('btn_chart_setting')))
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

if (!class_exists('LCNI_Chart_Shortcodes')) {
    class LCNI_Chart_Shortcodes extends LCNI_Chart_Shortcode {
    }
}
