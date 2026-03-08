<?php

if (! defined('ABSPATH')) {
    exit;
}

class LCNI_Industry_Data
{
    /** @var wpdb */
    private $wpdb;

    /** @var array<string, array<string, string>> */
    private $metric_map = array(
        'money_flow_share' => array('table' => 'lcni_industry_metrics', 'column' => 'money_flow_share'),
        'momentum' => array('table' => 'lcni_industry_metrics', 'column' => 'momentum'),
        'relative_strength' => array('table' => 'lcni_industry_metrics', 'column' => 'relative_strength'),
        'breadth' => array('table' => 'lcni_industry_metrics', 'column' => 'breadth'),
        'industry_index' => array('table' => 'lcni_industry_index', 'column' => 'industry_index'),
        'industry_return' => array('table' => 'lcni_industry_return', 'column' => 'industry_return'),
        'industry_volume' => array('table' => 'lcni_industry_return', 'column' => 'industry_volume'),
    );

    public function __construct($db = null)
    {
        global $wpdb;
        $this->wpdb = $db ?: $wpdb;
        $this->metric_map = $this->build_metric_map();
    }

    /** @return string[] */
    public function get_supported_metrics()
    {
        return array_keys($this->metric_map);
    }

    /** @return array<string, array<string, string>> */
    private function build_metric_map()
    {
        $map = $this->metric_map;
        $table_whitelist = array('lcni_industry_metrics', 'lcni_industry_index', 'lcni_thong_ke_nganh_icb_2_toan_thi_truong', 'lcni_industry_return');

        foreach ($table_whitelist as $table_name) {
            $prefixed_table = $this->wpdb->prefix . $table_name;
            $columns = $this->wpdb->get_results("SHOW COLUMNS FROM {$prefixed_table}", ARRAY_A);
            if (! is_array($columns)) {
                continue;
            }

            foreach ($columns as $column_meta) {
                $column = isset($column_meta['Field']) ? sanitize_key($column_meta['Field']) : '';
                $type = isset($column_meta['Type']) ? strtolower((string) $column_meta['Type']) : '';
                if ($column === '' || isset($map[$column])) {
                    continue;
                }
                if ($this->is_excluded_column($column) || ! $this->is_numeric_type($type)) {
                    continue;
                }

                $map[$column] = array('table' => $table_name, 'column' => $column);
            }
        }

        return $map;
    }

    private function is_excluded_column($column)
    {
        return in_array($column, array('id', 'id_icb2', 'timeframe', 'event_time', 'created_at', 'updated_at', 'name_icb2'), true);
    }

    private function is_numeric_type($type)
    {
        return (bool) preg_match('/(int|decimal|numeric|float|double|real|bigint|smallint|tinyint)/', $type);
    }

    /** @return array<string, string>|null */
    private function resolve_metric($metric)
    {
        return $this->metric_map[$metric] ?? null;
    }

    /** @param mixed $raw */
    private function normalize_event_time($raw)
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $raw_string = trim((string) $raw);
        if (preg_match('/^\d{12}$/', $raw_string) === 1) {
            $parsed = DateTime::createFromFormat('YmdHi', $raw_string, new DateTimeZone('UTC'));
            return $parsed ? $parsed->getTimestamp() : null;
        }
        if (preg_match('/^\d{14}$/', $raw_string) === 1) {
            $parsed = DateTime::createFromFormat('YmdHis', $raw_string, new DateTimeZone('UTC'));
            return $parsed ? $parsed->getTimestamp() : null;
        }
        if (preg_match('/^\d{8}$/', $raw_string) === 1) {
            $parsed = DateTime::createFromFormat('Ymd', $raw_string, new DateTimeZone('UTC'));
            return $parsed ? $parsed->getTimestamp() : null;
        }

        if (is_numeric($raw)) {
            $value = (int) $raw;
            if ($value > 2000000000000) {
                return (int) floor($value / 1000);
            }
            if ($value > 2000000000 && $value < 2000000000000) {
                return $value;
            }
            if ($value > 0 && $value < 1000000000) {
                return $value;
            }
        }

        $parsed = strtotime($raw_string);
        if ($parsed === false) {
            return null;
        }

        return (int) $parsed;
    }

    /** @param mixed $raw */
    private function normalize_event_time_key($raw)
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        return trim((string) $raw);
    }

    /** @param mixed $event_time_raw */
    public function format_event_time($event_time_raw)
    {
        $raw = trim((string) $event_time_raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\d{12}$/', $raw) === 1) {
            $parsed = DateTime::createFromFormat('YmdHi', $raw, new DateTimeZone('UTC'));
            return $parsed ? $parsed->format('d-m-Hi') : $raw;
        }
        if (preg_match('/^\d{14}$/', $raw) === 1) {
            $parsed = DateTime::createFromFormat('YmdHis', $raw, new DateTimeZone('UTC'));
            return $parsed ? $parsed->format('d-m-Hi') : $raw;
        }
        if (preg_match('/^\d{8}$/', $raw) === 1) {
            $parsed = DateTime::createFromFormat('Ymd', $raw, new DateTimeZone('UTC'));
            return $parsed ? $parsed->format('d-m-Y') : $raw;
        }
        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $raw) === 1) {
            return $raw;
        }

        $timestamp = $this->normalize_event_time($raw);
        if ($timestamp === null) {
            return $raw;
        }

        return gmdate('d-m-Hi', (int) $timestamp);
    }

    /** @return string[] */
    public function get_event_times($timeframe = '1D', $limit = 30, $metric = 'money_flow_share')
    {
        $timeframe = sanitize_text_field($timeframe);
        $limit = max(1, min(200, absint($limit)));
        $resolved = $this->resolve_metric($metric) ?: $this->resolve_metric('money_flow_share');

        $table = $this->wpdb->prefix . $resolved['table'];
        $sql = "SELECT DISTINCT event_time FROM {$table} WHERE timeframe = %s ORDER BY event_time DESC LIMIT %d";

        $prepared = $this->wpdb->prepare($sql, $timeframe, $limit * 4);
        $rows = (array) $this->wpdb->get_col($prepared);

        $normalized = array();
        foreach ($rows as $raw_time) {
            $key = $this->normalize_event_time_key($raw_time);
            if ($key === '') {
                continue;
            }
            if (! isset($normalized[$key])) {
                $normalized[$key] = array(
                    'key' => $key,
                    'sort' => $this->normalize_event_time($raw_time) ?? 0,
                );
            }
        }

        uasort($normalized, function ($left, $right) {
            if ($left['sort'] === $right['sort']) {
                return strcmp((string) $right['key'], (string) $left['key']);
            }

            return ((int) $right['sort']) <=> ((int) $left['sort']);
        });

        $keys = array_map(function ($entry) {
            return (string) $entry['key'];
        }, array_values($normalized));

        return array_slice($keys, 0, $limit);
    }

    /**
     * @param string[]  $event_times
     * @return array<int, array{industry:string, values:array<int, float|int|null>}>
     */
    public function get_metric_rows($metric, $timeframe, $event_times)
    {
        $resolved = $this->resolve_metric($metric);
        if (! $resolved) {
            return array();
        }

        $event_times = array_values(array_unique(array_map('strval', $event_times)));
        if (empty($event_times)) {
            return array();
        }

        $table = $this->wpdb->prefix . $resolved['table'];
        $icb_table = $this->wpdb->prefix . 'lcni_icb2';

        $sql = "SELECT src.id_icb2, icb.name_icb2, src.event_time, src.{$resolved['column']} AS metric_value
                FROM {$table} src
                INNER JOIN {$icb_table} icb ON src.id_icb2 = icb.id_icb2
                WHERE src.timeframe = %s
                ORDER BY icb.name_icb2 ASC, src.event_time DESC";

        $prepared = $this->wpdb->prepare($sql, sanitize_text_field($timeframe));
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        $time_index = array_flip($event_times);
        $grouped = array();

        foreach ((array) $rows as $row) {
            $industry = isset($row['name_icb2']) ? (string) $row['name_icb2'] : '';
            $event_time = $this->normalize_event_time_key($row['event_time'] ?? null);
            if ($industry === '' || $event_time === '' || ! isset($time_index[$event_time])) {
                continue;
            }

            if (! isset($grouped[$industry])) {
                $grouped[$industry] = array_fill(0, count($event_times), null);
            }

            $index = $time_index[$event_time];
            $raw_value = $row['metric_value'];
            if ($raw_value === null || $raw_value === '') {
                $grouped[$industry][$index] = null;
                continue;
            }

            $is_integer = preg_match('/(volume|count|qty|quantity)$/', (string) $metric) === 1;
            $grouped[$industry][$index] = $is_integer ? (int) $raw_value : (float) $raw_value;
        }

        $result = array();
        foreach ($grouped as $industry => $values) {
            $result[] = array('industry' => $industry, 'values' => $values);
        }

        return $result;
    }
}
