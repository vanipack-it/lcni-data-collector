<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Shortcode {

    const VERSION = '2.0.6';

    private $ajax;

    public function __construct() {
        $this->ajax = new LCNI_Chart_Ajax();

        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('rest_api_init', [$this->ajax, 'register_routes']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_chart', [$this, 'render']);
        add_shortcode('lcni_stock_chart_query', [$this, 'render']);
        add_shortcode('lcni_stock_query_form', [$this, 'render_query_form']);
    }

    public function register_assets() {
        $chart_script_path = LCNI_PATH . 'modules/chart/assets/chart.js';
        $chart_script_version = file_exists($chart_script_path)
            ? (string) filemtime($chart_script_path)
            : self::VERSION;

        $chart_style_path = LCNI_PATH . 'modules/chart/assets/chart.css';
        $chart_style_version = file_exists($chart_style_path)
            ? (string) filemtime($chart_style_path)
            : self::VERSION;

        wp_register_script('lcni-lightweight-charts', 'https://unpkg.com/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js', [], '4.2.3', true);
        wp_register_script('lcni-chart', LCNI_URL . 'modules/chart/assets/chart.js', ['lcni-lightweight-charts'], $chart_script_version, true);
        wp_register_style('lcni-chart-ui', LCNI_URL . 'modules/chart/assets/chart.css', [], $chart_style_version);
    }

    public function render($atts = []) {
        $atts = shortcode_atts(['symbol' => '', 'limit' => 200, 'height' => 420], $atts, 'lcni_stock_chart');

        $symbol = $this->sanitize_symbol($atts['symbol']);
        if ($symbol === '') {
            return '';
        }

        wp_enqueue_script('lcni-chart');
        wp_enqueue_style('lcni-chart-ui');

        $limit = max(10, min(1000, (int) $atts['limit']));
        $height = max(260, min(1000, (int) $atts['height']));

        return sprintf(
            '<div data-lcni-chart data-symbol="%1$s" data-limit="%2$d" data-main-height="%3$d"></div>',
            esc_attr($symbol),
            $limit,
            $height
        );
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

        ob_start();
        ?>
        <form method="get" class="lcni-stock-query-form" data-lcni-stock-query-form>
            <label>
                <span class="screen-reader-text"><?php echo esc_html($atts['placeholder']); ?></span>
                <input type="text" name="<?php echo esc_attr($query_param); ?>" value="<?php echo esc_attr($symbol); ?>" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" style="padding:8px 10px; min-width:160px;">
            </label>
            <button type="submit" class="lcni-btn lcni-btn-btn_stock_view" style="padding:8px 12px;"><?php echo esc_html((string) $atts['button_text']); ?></button>
        </form>
        <?php

        return (string) ob_get_clean();
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
