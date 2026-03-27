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
        add_shortcode('lcni_industry_monitor_compact', array($this, 'render_compact_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_lcni_industry_data', array($this, 'ajax_industry_data'));
        add_action('wp_ajax_nopriv_lcni_industry_data', array($this, 'ajax_industry_data'));
    }

    public function render_compact_shortcode($atts = array())
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['compact'] = '1';

        return $this->render_shortcode($atts);
    }

    public function enqueue_assets()
    {
        wp_register_style(
            'lcni-industry-monitor',
            LCNI_INDUSTRY_MONITOR_URL . 'public/css/industry-monitor.css',
            array('lcni-ui-table'),   // lcni-ui-table load trước, industry-monitor override sau
            LCNI_INDUSTRY_MONITOR_VERSION
        );

        wp_register_script(
            'lcni-industry-monitor',
            LCNI_INDUSTRY_MONITOR_URL . 'public/js/industry-monitor.js',
            array('lcni-main-js'),
            LCNI_INDUSTRY_MONITOR_VERSION,
            true
        );
    }

    public function render_shortcode($atts = array())
    {
        $atts = shortcode_atts(
            array(
                'timeframe' => '1D',
                'metric' => '',
                'id_icb2' => '',
                'session' => '',
                'compact' => '',
            ),
            $atts,
            'lcni_industry_monitor'
        );

        wp_enqueue_style('lcni-industry-monitor');
        wp_enqueue_script('lcni-industry-monitor');

        $settings = LCNI_Industry_Settings::get_settings();
        $metric_labels = LCNI_Industry_Settings::get_metric_labels();
        $supported_metrics = $this->data->get_supported_metrics();

        $metrics = array_values(array_intersect($supported_metrics, (array) $settings['enabled_metrics']));
        if (empty($metrics)) {
            $metrics = $supported_metrics;
        }

        $metric_options = array();
        foreach ($metrics as $metric_key) {
            $metric_options[] = array(
                'key' => $metric_key,
                'label' => $metric_labels[$metric_key] ?? $metric_key,
            );
        }

        if (empty($metric_options)) {
            foreach ((array) $metric_labels as $metric_key => $metric_label) {
                $metric_key = sanitize_key((string) $metric_key);
                if ($metric_key === '') {
                    continue;
                }

                $metric_options[] = array(
                    'key' => $metric_key,
                    'label' => (string) $metric_label,
                );
            }
        }

        $requested_metric = sanitize_key((string) $atts['metric']);
        $default_metric = in_array($requested_metric, $metrics, true) ? $requested_metric : ($metric_options[0]['key'] ?? 'money_flow_share');
        $default_timeframe = strtoupper(trim((string) $atts['timeframe']));
        if ($default_timeframe === '') {
            $default_timeframe = '1D';
        }

        $id_icb2_list = $this->parse_id_list($atts['id_icb2']);
        $requested_session = absint($atts['session']);
        $default_session_limit = $requested_session > 0 ? $requested_session : (int) $settings['default_session_limit'];
        $default_session_limit = max(1, min(200, $default_session_limit));
        $is_compact_mode = ! empty($id_icb2_list) || $requested_session > 0 || ! empty($atts['compact']);
        $container_id = 'lcni-industry-monitor-' . wp_generate_password(8, false, false);

        $nonce = wp_create_nonce('lcni_industry_data_nonce');
        $default_payload = $this->get_cached_payload($default_metric, $default_timeframe, $default_session_limit, $id_icb2_list);

        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
            'defaultMetric' => $default_metric,
            'defaultTimeframe' => $default_timeframe,
            'defaultSessionLimit' => $default_session_limit,
            'filterBaseUrl' => $settings['industry_filter_url'],
            'rowHoverEnabled' => ! empty($settings['row_hover_enabled']),
            'eventTimeColumnWidth' => (int) $settings['event_time_col_width'],
            'cellRules' => array_values((array) ($settings['cell_rules'] ?? array())),
            'rowGradientRules' => array_values((array) ($settings['row_gradient_rules'] ?? array())),
            'initialPayload' => $default_payload,
            'idIcb2' => $id_icb2_list,
            'showFullTableButton' => $is_compact_mode,
            'fullTableUrl' => (string) ($settings['compact_full_table_url'] ?? ''),
        );

        wp_add_inline_script('lcni-industry-monitor', 'window.LCNIIndustryMonitors = window.LCNIIndustryMonitors || {}; window.LCNIIndustryMonitors[' . wp_json_encode($container_id) . '] = ' . wp_json_encode($config) . ';', 'before');

        ob_start();
        include LCNI_INDUSTRY_MONITOR_PATH . 'templates/industry-table.php';
        return ob_get_clean();
    }


    /**
     * @return array{columns:array<int,string>,rawColumns:array<int,string>,rows:array<int,array<string,mixed>>}
     */
    private function build_payload($metric, $timeframe, $limit, $id_icb2_list = array())
    {
        $columns = $this->data->get_event_times($timeframe, $limit, $metric, $id_icb2_list);
        if (empty($columns)) {
            $timeframe = $this->data->resolve_timeframe($metric, $timeframe);
            $columns = $this->data->get_event_times($timeframe, $limit, $metric, $id_icb2_list);
        }

        $rows = $this->data->get_metric_rows($metric, $timeframe, $columns, $id_icb2_list);

        return array(
            'columns' => array_map(array($this->data, 'format_event_time'), $columns),
            'rawColumns' => $columns,
            'rows' => $rows,
        );
    }

    /** @return array<string,mixed> */
    private function get_cached_payload($metric, $timeframe, $limit, $id_icb2_list = array())
    {
        $id_icb2_list = array_values(array_filter(array_map('absint', (array) $id_icb2_list)));
        $cache_key = 'lcni_industry_' . md5($metric . '|' . $timeframe . '|' . $limit . '|' . implode(',', $id_icb2_list));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->build_payload($metric, $timeframe, $limit, $id_icb2_list);
        set_transient($cache_key, $payload, 60);

        return $payload;
    }

    public function ajax_industry_data()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (! wp_verify_nonce($nonce, 'lcni_industry_data_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce.'), 403);
        }

        $settings = LCNI_Industry_Settings::get_settings();
        $allowed_metrics = array_values(array_intersect($this->data->get_supported_metrics(), (array) $settings['enabled_metrics']));
        if (empty($allowed_metrics)) {
            $allowed_metrics = $this->data->get_supported_metrics();
        }

        $metric = isset($_POST['metric']) ? sanitize_key(wp_unslash($_POST['metric'])) : 'money_flow_share';
        $timeframe = isset($_POST['timeframe']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['timeframe']))) : '1D';
        if ($timeframe === '') {
            $timeframe = '1D';
        }
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : (int) $settings['default_session_limit'];
        $id_icb2_list = isset($_POST['id_icb2']) ? $this->parse_id_list(wp_unslash($_POST['id_icb2'])) : array();

        if (! in_array($metric, $allowed_metrics, true)) {
            wp_send_json_error(array('message' => 'Unsupported metric.'), 400);
        }

        $payload = $this->get_cached_payload($metric, $timeframe, $limit, $id_icb2_list);

        wp_send_json_success($payload);
    }

    /** @param mixed $raw */
    private function parse_id_list($raw)
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = explode(',', (string) $raw);
        }

        return array_values(array_filter(array_map('absint', $parts)));
    }
}
