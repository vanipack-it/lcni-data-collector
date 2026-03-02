<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Builder_Shortcode {

    const VERSION = '5.3.9g';

    public function __construct() {
        add_action('init', [$this, 'register_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_shortcode() {
        add_shortcode('lcni_chart', [$this, 'render_shortcode']);
    }

    public function register_assets() {
        wp_register_script('lcni-echarts', LCNI_URL . 'assets/vendor/echarts.min.js', [], self::VERSION, true);
        wp_register_script('lcni-chart-builder', LCNI_URL . 'modules/chart-builder/assets/chart-builder.js', ['lcni-echarts', 'lcni-main-js'], self::VERSION, true);
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'slug' => '',
            'sync_group' => '',
        ], $atts, 'lcni_chart');

        $chart = !empty($atts['id'])
            ? LCNI_Chart_Builder_Repository::find_by_id((int) $atts['id'])
            : LCNI_Chart_Builder_Repository::find_by_slug((string) $atts['slug']);

        if (!$chart) {
            return '<!-- lcni_chart_not_found -->';
        }

        $payload = LCNI_Chart_Builder_Service::build_shortcode_payload($chart);

        wp_enqueue_script('lcni-chart-builder');

        return sprintf(
            '<div class="lcni-chart-builder" data-lcni-chart-builder="%1$s" data-sync-group="%2$s" style="width:100%%;height:420px;"></div>',
            esc_attr(wp_json_encode($payload)),
            esc_attr(sanitize_key((string) $atts['sync_group']))
        );
    }
}
