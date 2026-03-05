<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Industry_Shortcodes {

    const VERSION = '1.0.0';

    public function __construct() {
        add_action('init', [$this, 'register_shortcodes']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_shortcodes() {
        add_shortcode('lcni_industry_dashboard', [$this, 'render_dashboard']);
    }

    public function register_assets() {
        $script_path = LCNI_PATH . 'assets/js/lcni-industry-dashboard.js';
        $version = file_exists($script_path) ? (string) filemtime($script_path) : self::VERSION;

        wp_register_script(
            'lcni-industry-dashboard',
            LCNI_URL . 'assets/js/lcni-industry-dashboard.js',
            ['lcni-main-js', 'echarts'],
            $version,
            true
        );
    }

    public function render_dashboard($atts = []) {
        $atts = shortcode_atts([
            'timeframe' => '1D',
            'limit' => 20,
            'title' => 'Industry Leadership',
        ], $atts, 'lcni_industry_dashboard');

        wp_enqueue_script('lcni-industry-dashboard');

        return sprintf(
            '<div data-lcni-industry-dashboard data-api-base="%1$s" data-timeframe="%2$s" data-limit="%3$d" data-title="%4$s"></div>',
            esc_url(rest_url('lcni/v1/industry/dashboard')),
            esc_attr(strtoupper((string) $atts['timeframe'])),
            max(1, min(100, (int) $atts['limit'])),
            esc_attr((string) $atts['title'])
        );
    }
}
