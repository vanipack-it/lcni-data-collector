<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Builder_Service {

    public static function sanitize_payload($raw) {
        $config = [
            'xAxis' => sanitize_key((string) ($raw['xAxis'] ?? 'event_time')),
            'yAxis' => sanitize_key((string) ($raw['yAxis'] ?? '')),
            'series' => [],
            'filters' => [],
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

        $filter_fields = isset($raw['filter_field']) ? (array) $raw['filter_field'] : [];
        foreach ($filter_fields as $field) {
            $sanitized = sanitize_key((string) $field);
            if ($sanitized !== '') {
                $config['filters'][] = $sanitized;
            }
        }
        $config['filters'] = array_values(array_unique($config['filters']));

        return [
            'id' => isset($raw['id']) ? absint($raw['id']) : 0,
            'name' => sanitize_text_field((string) ($raw['name'] ?? '')),
            'slug' => sanitize_title((string) ($raw['slug'] ?? '')),
            'chart_type' => sanitize_key((string) ($raw['chart_type'] ?? 'multi_line')),
            'data_source' => sanitize_key((string) ($raw['data_source'] ?? 'thong_ke_thi_truong')),
            'config_json' => $config,
        ];
    }

    private static function get_column_label_map() {
        $configured = get_option('lcni_column_labels', []);
        if (!is_array($configured)) {
            $configured = [];
        }

        $label_map = [];
        foreach ($configured as $key => $item) {
            if (is_array($item)) {
                $data_key = sanitize_key((string) ($item['data_key'] ?? ''));
                $label = sanitize_text_field((string) ($item['label'] ?? ''));
            } else {
                $data_key = sanitize_key((string) $key);
                $label = sanitize_text_field((string) $item);
            }

            if ($data_key !== '' && $label !== '') {
                $label_map[$data_key] = $label;
            }
        }

        return $label_map;
    }

    public static function build_shortcode_payload($chart) {
        $config = json_decode((string) ($chart['config_json'] ?? '{}'), true);
        $config = is_array($config) ? $config : [];
        $rows = LCNI_Chart_Builder_Repository::query_chart_data($chart);
        $label_map = self::get_column_label_map();

        $series_labels = [];
        foreach ((array) ($config['series'] ?? []) as $item) {
            $field = sanitize_key((string) ($item['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $series_labels[$field] = $label_map[$field] ?? $field;
        }

        $filter_fields = [];
        foreach ((array) ($config['filters'] ?? []) as $filter_field) {
            $sanitized = sanitize_key((string) $filter_field);
            if ($sanitized !== '') {
                $filter_fields[] = $sanitized;
            }
        }
        $filter_fields = array_values(array_unique($filter_fields));

        $chart_type = (string) ($chart['chart_type'] ?? 'multi_line');
        if ($chart_type === 'heatmap_matrix') {
            $x_axis = sanitize_key((string) ($config['xAxis'] ?? 'timeframe'));
            $y_axis = sanitize_key((string) ($config['yAxis'] ?? 'icb2'));
            $value_field = '';
            foreach ((array) ($config['series'] ?? []) as $item) {
                $candidate = sanitize_key((string) ($item['field'] ?? ''));
                if ($candidate !== '') {
                    $value_field = $candidate;
                    break;
                }
            }

            $x_values = [];
            $y_values = [];
            $matrix = [];

            foreach ((array) $rows as $row) {
                $x_value = sanitize_text_field((string) ($row[$x_axis] ?? ''));
                $y_value = sanitize_text_field((string) ($row[$y_axis] ?? ''));
                if ($x_value === '' || $y_value === '') {
                    continue;
                }

                if (!isset($x_values[$x_value])) {
                    $x_values[$x_value] = count($x_values);
                }
                if (!isset($y_values[$y_value])) {
                    $y_values[$y_value] = count($y_values);
                }

                $raw_value = $value_field !== '' ? ($row[$value_field] ?? null) : null;
                $value = is_numeric($raw_value) ? (float) $raw_value : null;
                $matrix[] = [$x_values[$x_value], $y_values[$y_value], $value];
            }

            $rows = [
                'x' => array_keys($x_values),
                'y' => array_keys($y_values),
                'data' => $matrix,
            ];
        }

        return [
            'id' => (int) ($chart['id'] ?? 0),
            'slug' => (string) ($chart['slug'] ?? ''),
            'name' => (string) ($chart['name'] ?? ''),
            'chart_type' => $chart_type,
            'config' => $config,
            'data' => $rows,
            'series_labels' => $series_labels,
            'filter_fields' => $filter_fields,
            'filter_labels' => array_intersect_key($label_map, array_fill_keys($filter_fields, true)),
            'filter_options' => LCNI_Chart_Builder_Repository::get_filter_options($chart, $filter_fields),
        ];
    }
}
