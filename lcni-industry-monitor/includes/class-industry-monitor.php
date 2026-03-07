<?php

if (! defined('ABSPATH')) {
    exit;
}

class LCNI_Industry_Monitor
{
    /** @var LCNI_Industry_Data */
    private $data;

    public function __construct(LCNI_Industry_Data $data)
    {
        $this->data = $data;
    }

    public function register_hooks()
    {
        add_shortcode('lcni_industry_monitor', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_lcni_industry_data', array($this, 'ajax_industry_data'));
        add_action('wp_ajax_nopriv_lcni_industry_data', array($this, 'ajax_industry_data'));
    }

    public function enqueue_assets()
    {
        wp_register_style(
            'lcni-industry-monitor',
            LCNI_INDUSTRY_MONITOR_URL . 'public/css/industry-monitor.css',
            array(),
            LCNI_INDUSTRY_MONITOR_VERSION
        );

        wp_register_script(
            'lcni-industry-monitor',
            LCNI_INDUSTRY_MONITOR_URL . 'public/js/industry-monitor.js',
            array(),
            LCNI_INDUSTRY_MONITOR_VERSION,
            true
        );
    }

    public function render_shortcode($atts = array())
    {
        wp_enqueue_style('lcni-industry-monitor');
        wp_enqueue_script('lcni-industry-monitor');

        $nonce = wp_create_nonce('lcni_industry_data_nonce');
        wp_localize_script(
            'lcni-industry-monitor',
            'LCNIIndustryMonitor',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => $nonce,
                'defaultTimeframe' => '1D',
                'defaultMetric' => 'money_flow_share',
            )
        );

        $metrics = $this->data->get_supported_metrics();

        ob_start();
        include LCNI_INDUSTRY_MONITOR_PATH . 'templates/industry-table.php';
        return ob_get_clean();
    }

    public function ajax_industry_data()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (! wp_verify_nonce($nonce, 'lcni_industry_data_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce.'), 403);
        }

        $metric = isset($_POST['metric']) ? sanitize_key(wp_unslash($_POST['metric'])) : 'money_flow_share';
        $timeframe = isset($_POST['timeframe']) ? sanitize_text_field(wp_unslash($_POST['timeframe'])) : '1D';
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 30;

        if (! in_array($metric, $this->data->get_supported_metrics(), true)) {
            wp_send_json_error(array('message' => 'Unsupported metric.'), 400);
        }

        $columns = $this->data->get_event_times($timeframe, $limit);
        $rows = $this->data->get_metric_rows($metric, $timeframe, $columns);

        wp_send_json_success(
            array(
                'columns' => $columns,
                'rows' => $rows,
            )
        );
    }
}
