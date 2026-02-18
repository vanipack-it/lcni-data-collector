<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Stock_Signals_Shortcodes {

    const VERSION = '1.0.0';

    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_stock_signals', [$this, 'render_fixed']);
        add_shortcode('lcni_stock_signals_query', [$this, 'render_query']);
    }

    public function register_assets() {
        $sync_script_path = LCNI_PATH . 'assets/js/lcni-stock-sync.js';
        $sync_version = file_exists($sync_script_path) ? (string) filemtime($sync_script_path) : self::VERSION;

        wp_register_script('lcni-stock-sync', LCNI_URL . 'assets/js/lcni-stock-sync.js', [], $sync_version, true);

        $script_path = LCNI_PATH . 'assets/js/lcni-stock-signals.js';
        $version = file_exists($script_path) ? (string) filemtime($script_path) : self::VERSION;

        wp_register_script('lcni-stock-signals', LCNI_URL . 'assets/js/lcni-stock-signals.js', ['lcni-stock-sync'], $version, true);
    }

    public function render_fixed($atts = []) {
        $atts = shortcode_atts([
            'symbol' => '',
            'version' => self::VERSION,
        ], $atts, 'lcni_stock_signals');

        $symbol = $this->sanitize_symbol($atts['symbol']);
        if ($symbol === '') {
            return '';
        }

        return $this->render_container($symbol, '', (string) $atts['version']);
    }

    public function render_query($atts = []) {
        $atts = shortcode_atts([
            'param' => 'symbol',
            'default_symbol' => '',
            'version' => self::VERSION,
        ], $atts, 'lcni_stock_signals_query');

        $param = sanitize_key((string) $atts['param']);
        if ($param === '') {
            $param = 'symbol';
        }
        $query_symbol = isset($_GET[$param]) ? wp_unslash((string) $_GET[$param]) : '';
        $symbol = $this->sanitize_symbol($query_symbol);
        if ($symbol === '') {
            $symbol = $this->sanitize_symbol($atts['default_symbol']);
        }

        return $this->render_container($symbol, $param, (string) $atts['version']);
    }

    private function render_container($symbol, $query_param, $version) {
        wp_enqueue_script('lcni-stock-signals');

        return sprintf(
            '<div data-lcni-stock-signals data-symbol="%1$s" data-query-param="%2$s" data-api-base="%3$s" data-version="%4$s"></div>',
            esc_attr($symbol),
            esc_attr($query_param),
            esc_url(rest_url('lcni/v1/stock-signals')),
            esc_attr($version)
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
