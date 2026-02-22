<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Shortcode {

    const VERSION = '2.0.8';

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
        $sync_script_path = LCNI_PATH . 'assets/js/lcni-stock-sync.js';
        $sync_version = file_exists($sync_script_path) ? (string) filemtime($sync_script_path) : self::VERSION;

        wp_register_script('lcni-stock-sync', LCNI_URL . 'assets/js/lcni-stock-sync.js', [], $sync_version, true);

        $chart_script_path = LCNI_PATH . 'modules/chart/assets/chart.js';
        $chart_script_version = file_exists($chart_script_path)
            ? (string) filemtime($chart_script_path)
            : self::VERSION;

        $echarts_engine_path = LCNI_PATH . 'modules/chart/assets/lcni-echarts-engine.js';
        $echarts_engine_version = file_exists($echarts_engine_path)
            ? (string) filemtime($echarts_engine_path)
            : self::VERSION;

        $chart_style_path = LCNI_PATH . 'modules/chart/assets/chart.css';
        $chart_style_version = file_exists($chart_style_path)
            ? (string) filemtime($chart_style_path)
            : self::VERSION;

        wp_register_script('lcni-echarts', LCNI_URL . 'assets/vendor/echarts.min.js', [], self::VERSION, true);
        wp_register_script('lcni-echarts-engine', LCNI_URL . 'modules/chart/assets/lcni-echarts-engine.js', ['lcni-echarts'], $echarts_engine_version, true);
        wp_register_script('lcni-chart', LCNI_URL . 'modules/chart/assets/chart.js', ['lcni-stock-sync', 'lcni-echarts-engine'], $chart_script_version, true);
        wp_register_style('lcni-chart-ui', LCNI_URL . 'modules/chart/assets/chart.css', [], $chart_style_version);
    }

    public function render($atts = [], $content = '', $shortcode_tag = 'lcni_stock_chart') {
        $defaults = [
            'symbol' => '',
            'limit' => 200,
            'height' => 420,
            'param' => 'symbol',
            'default_symbol' => '',
        ];
        $atts = shortcode_atts($defaults, $atts, $shortcode_tag);

        $symbol = $shortcode_tag === 'lcni_stock_chart_query'
            ? $this->resolve_query_symbol($atts['param'], $atts['default_symbol'])
            : lcni_get_current_symbol($atts['symbol']);
        if ($symbol === '') {
            return '';
        }

        $limit = $this->sanitize_limit($atts['limit']);
        $height = $this->sanitize_height($atts['height']);

        wp_enqueue_script('lcni-chart');
        wp_enqueue_style('lcni-chart-ui');

        return sprintf(
            '<div data-lcni-chart data-lcni-symbol="%1$s" data-lcni-limit="%2$d" data-lcni-height="%3$d"></div>',
            esc_attr($symbol),
            (int) $limit,
            (int) $height
        );
    }

    public function render_query_form($atts = []) {
        $atts = shortcode_atts(['param' => 'symbol', 'placeholder' => 'Nhập mã cổ phiếu', 'button_text' => 'Xem chart', 'default_symbol' => ''], $atts, 'lcni_stock_query_form');
        $query_param = sanitize_key($atts['param']);
        if ($query_param === '') {
            $query_param = 'symbol';
        }

        $symbol = $this->sanitize_symbol($atts['default_symbol']);

        wp_enqueue_style('lcni-chart-ui');

        return sprintf(
            '<div data-lcni-stock-query-form data-query-param="%1$s" data-default-symbol="%2$s" data-placeholder="%3$s" data-button-text="%4$s"></div>',
            esc_attr($query_param),
            esc_attr($symbol),
            esc_attr((string) $atts['placeholder']),
            esc_attr((string) $atts['button_text'])
        );
    }
    private function sanitize_symbol($symbol) {
        $symbol = strtoupper(sanitize_text_field((string) $symbol));
        if ($symbol === '') {
            return '';
        }

        return preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol) === 1 ? $symbol : '';
    }

    private function sanitize_limit($limit) {
        $value = (int) $limit;
        if ($value <= 0) {
            return 200;
        }

        return min($value, 500);
    }

    private function sanitize_height($height) {
        $value = (int) $height;
        if ($value < 240) {
            return 420;
        }

        return min($value, 1200);
    }

    private function resolve_query_symbol($param, $default_symbol) {
        $query_param = sanitize_key((string) $param);
        if ($query_param === '') {
            $query_param = 'symbol';
        }

        $query_value = isset($_GET[$query_param]) ? wp_unslash((string) $_GET[$query_param]) : '';
        $symbol = $this->sanitize_symbol($query_value);
        if ($symbol !== '') {
            return $symbol;
        }

        return lcni_get_current_symbol($default_symbol);
    }

}


if (!class_exists('LCNI_Chart_Shortcodes')) {
    class LCNI_Chart_Shortcodes extends LCNI_Chart_Shortcode {
    }
}
