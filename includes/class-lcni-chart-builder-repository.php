<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Builder_Repository {

    public static function table_name() {
        global $wpdb;

        return $wpdb->prefix . 'lcni_charts';
    }

    public static function list_charts() {
        global $wpdb;

        $table = self::table_name();

        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A);
    }

    public static function find_by_id($id) {
        global $wpdb;

        $table = self::table_name();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id), ARRAY_A);
    }

    public static function find_by_slug($slug) {
        global $wpdb;

        $table = self::table_name();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", sanitize_title($slug)), ARRAY_A);
    }

    public static function upsert_chart($payload) {
        global $wpdb;

        $table = self::table_name();
        $data = [
            'name' => sanitize_text_field((string) ($payload['name'] ?? '')),
            'slug' => sanitize_title((string) ($payload['slug'] ?? '')),
            'chart_type' => sanitize_key((string) ($payload['chart_type'] ?? 'multi_line')),
            'data_source' => sanitize_key((string) ($payload['data_source'] ?? '')),
            'config_json' => wp_json_encode($payload['config_json'] ?? []),
        ];

        if (!empty($payload['id'])) {
            $wpdb->update($table, $data, ['id' => (int) $payload['id']], ['%s', '%s', '%s', '%s', '%s'], ['%d']);
            return (int) $payload['id'];
        }

        $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s']);

        return (int) $wpdb->insert_id;
    }

    public static function delete_chart($id) {
        global $wpdb;

        $table = self::table_name();
        $wpdb->delete($table, ['id' => (int) $id], ['%d']);
    }

    private static function get_allowed_tables() {
        global $wpdb;

        return [
            'thong_ke_thi_truong' => $wpdb->prefix . 'lcni_thong_ke_thi_truong',
            'ohlc_latest' => $wpdb->prefix . 'lcni_ohlc_latest',
            'money_flow' => $wpdb->prefix . 'lcni_money_flow',
            'stock_stats' => $wpdb->prefix . 'lcni_stock_stats',
        ];
    }

    private static function get_table_name_by_key($table_key) {
        $allowed = self::get_allowed_tables();

        return $allowed[$table_key] ?? '';
    }

    public static function get_table_columns($table_key) {
        global $wpdb;

        $table = self::get_table_name_by_key($table_key);
        if ($table === '') {
            return [];
        }

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return [];
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");

        return is_array($columns) ? array_values(array_filter(array_map('sanitize_key', $columns))) : [];
    }

    private static function build_where_sql($config, $fields_whitelist) {
        $where = [];
        $params = [];

        $filters = isset($config['filters']) && is_array($config['filters']) ? $config['filters'] : [];
        foreach ($filters as $filter_field) {
            $field = sanitize_key((string) $filter_field);
            if ($field === '' || !in_array($field, $fields_whitelist, true)) {
                continue;
            }

            $query_key = 'lcni_filter_' . $field;
            if (!isset($_GET[$query_key])) {
                continue;
            }

            $value = sanitize_text_field((string) wp_unslash($_GET[$query_key]));
            if ($value === '') {
                continue;
            }

            $where[] = "`{$field}` = %s";
            $params[] = $value;
        }

        return [
            'sql' => empty($where) ? '' : (' WHERE ' . implode(' AND ', $where)),
            'params' => $params,
        ];
    }

    public static function query_chart_data($chart) {
        global $wpdb;

        $source_key = sanitize_key((string) ($chart['data_source'] ?? ''));
        $config = json_decode((string) ($chart['config_json'] ?? '{}'), true);

        $table = self::get_table_name_by_key($source_key);
        if ($table === '') {
            return [];
        }

        $all_columns = self::get_table_columns($source_key);
        if (empty($all_columns)) {
            return [];
        }

        $x_axis = sanitize_key((string) ($config['xAxis'] ?? 'event_time'));
        if ($x_axis === '' || !in_array($x_axis, $all_columns, true)) {
            $x_axis = 'event_time';
        }

        $series = is_array($config['series'] ?? null) ? $config['series'] : [];
        $fields = [$x_axis];

        foreach ($series as $item) {
            $field = sanitize_key((string) ($item['field'] ?? ''));
            if ($field !== '' && in_array($field, $all_columns, true)) {
                $fields[] = $field;
            }
        }

        $fields = array_values(array_unique(array_filter($fields)));
        if (empty($fields)) {
            return [];
        }

        $safe_fields = array_map(static function ($field) {
            return "`{$field}`";
        }, $fields);

        $where_data = self::build_where_sql(is_array($config) ? $config : [], $all_columns);
        $sql = "SELECT " . implode(', ', $safe_fields) . " FROM {$table}" . $where_data['sql'] . " ORDER BY `{$x_axis}` ASC LIMIT 300";

        if (!empty($where_data['params'])) {
            $sql = $wpdb->prepare($sql, ...$where_data['params']);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function get_filter_options($chart, $filter_fields) {
        global $wpdb;

        $source_key = sanitize_key((string) ($chart['data_source'] ?? ''));
        $config = json_decode((string) ($chart['config_json'] ?? '{}'), true);
        $table = self::get_table_name_by_key($source_key);
        if ($table === '') {
            return [];
        }

        $all_columns = self::get_table_columns($source_key);
        if (empty($all_columns)) {
            return [];
        }

        $result = [];
        foreach ((array) $filter_fields as $raw_field) {
            $field = sanitize_key((string) $raw_field);
            if ($field === '' || !in_array($field, $all_columns, true)) {
                continue;
            }

            $sql = "SELECT DISTINCT `{$field}` AS v FROM {$table} WHERE `{$field}` IS NOT NULL AND `{$field}` <> '' ORDER BY `{$field}` ASC LIMIT 100";
            $values = $wpdb->get_col($sql);
            $result[$field] = array_values(array_filter(array_map(static function ($value) {
                return sanitize_text_field((string) $value);
            }, (array) $values), static function ($value) {
                return $value !== '';
            }));
        }

        return $result;
    }
}
