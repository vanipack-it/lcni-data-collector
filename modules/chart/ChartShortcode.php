<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Shortcode {

    const VERSION = '2.0.7';

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

        $chart_style_path = LCNI_PATH . 'modules/chart/assets/chart.css';
        $chart_style_version = file_exists($chart_style_path)
            ? (string) filemtime($chart_style_path)
            : self::VERSION;

        wp_register_script('lcni-lightweight-charts', 'https://unpkg.com/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js', [], '4.2.3', true);
        wp_register_script('lcni-chart', LCNI_URL . 'modules/chart/assets/chart.js', ['lcni-lightweight-charts', 'lcni-stock-sync'], $chart_script_version, true);
        wp_register_style('lcni-chart-ui', LCNI_URL . 'modules/chart/assets/chart.css', [], $chart_style_version);
    }

    public function render($atts = []) {
        $atts = shortcode_atts(['symbol' => ''], $atts, 'lcni_stock_chart');

        $symbol = lcni_get_current_symbol($atts['symbol']);
        if ($symbol === '') {
            return '';
        }

        wp_enqueue_script('lcni-chart');
        wp_enqueue_style('lcni-chart-ui');

        return '<div data-lcni-chart></div>';
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

}


if (!class_exists('LCNI_Chart_Shortcodes')) {
    class LCNI_Chart_Shortcodes extends LCNI_Chart_Shortcode {
    }
}
