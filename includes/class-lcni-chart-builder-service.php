<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Builder_Service {

    public static function sanitize_payload($raw) {
        $config = [
            'xAxis' => sanitize_key((string) ($raw['xAxis'] ?? 'event_time')),
            'series' => [],
            'timeframe' => sanitize_text_field((string) ($raw['timeframe'] ?? '1D')),
            'market' => sanitize_text_field((string) ($raw['market'] ?? 'VNINDEX')),
            'template' => sanitize_key((string) ($raw['template'] ?? 'multi_line')),
        ];

        $series_names = isset($raw['series_name']) ? (array) $raw['series_name'] : [];
        $series_fields = isset($raw['series_field']) ? (array) $raw['series_field'] : [];
        $series_types = isset($raw['series_type']) ? (array) $raw['series_type'] : [];
        $series_colors = isset($raw['series_color']) ? (array) $raw['series_color'] : [];
        $series_stacks = isset($raw['series_stack']) ? (array) $raw['series_stack'] : [];
        $series_area = isset($raw['series_area']) ? (array) $raw['series_area'] : [];
        $series_labels = isset($raw['series_label_show']) ? (array) $raw['series_label_show'] : [];

        $count = max(count($series_names), count($series_fields), count($series_types), count($series_colors));
        for ($i = 0; $i < $count; $i++) {
            $name = sanitize_text_field((string) ($series_names[$i] ?? ''));
            $field = sanitize_key((string) ($series_fields[$i] ?? ''));
            $type = sanitize_key((string) ($series_types[$i] ?? 'line'));
            $color = sanitize_hex_color((string) ($series_colors[$i] ?? '')) ?: '';
            $stack = !empty($series_stacks[$i]);
            $area = !empty($series_area[$i]);
            $label_show = !empty($series_labels[$i]);
            if ($name === '' || $field === '') {
                continue;
            }
            $config['series'][] = [
                'name' => $name,
                'field' => $field,
                'type' => in_array($type, ['line', 'bar'], true) ? $type : 'line',
                'color' => $color,
                'stack' => $stack,
                'area' => $area,
                'label_show' => $label_show,
            ];
        }

        return [
            'id' => isset($raw['id']) ? absint($raw['id']) : 0,
            'name' => sanitize_text_field((string) ($raw['name'] ?? '')),
            'slug' => sanitize_title((string) ($raw['slug'] ?? '')),
            'chart_type' => sanitize_key((string) ($raw['chart_type'] ?? 'multi_line')),
            'data_source' => sanitize_key((string) ($raw['data_source'] ?? 'thong_ke_thi_truong')),
            'config_json' => $config,
        ];
    }

    public static function build_shortcode_payload($chart) {
        $config = json_decode((string) ($chart['config_json'] ?? '{}'), true);
        $rows = LCNI_Chart_Builder_Repository::query_chart_data($chart);

        return [
            'id' => (int) ($chart['id'] ?? 0),
            'slug' => (string) ($chart['slug'] ?? ''),
            'name' => (string) ($chart['name'] ?? ''),
            'chart_type' => (string) ($chart['chart_type'] ?? 'multi_line'),
            'config' => is_array($config) ? $config : [],
            'data' => $rows,
        ];
    }
}
