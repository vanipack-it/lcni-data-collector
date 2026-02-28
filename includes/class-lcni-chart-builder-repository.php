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

    public static function get_table_columns($table_key) {
        global $wpdb;

        $allowed = [
            'thong_ke_thi_truong' => $wpdb->prefix . 'lcni_thong_ke_thi_truong',
            'ohlc_latest' => $wpdb->prefix . 'lcni_ohlc_latest',
            'money_flow' => $wpdb->prefix . 'lcni_money_flow',
            'stock_stats' => $wpdb->prefix . 'lcni_stock_stats',
        ];

        if (!isset($allowed[$table_key])) {
            return [];
        }

        $table = $allowed[$table_key];
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return [];
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");

        return is_array($columns) ? array_values(array_filter(array_map('sanitize_key', $columns))) : [];
    }

    public static function query_chart_data($chart) {
        global $wpdb;

        $source_key = sanitize_key((string) ($chart['data_source'] ?? ''));
        $config = json_decode((string) ($chart['config_json'] ?? '{}'), true);

        $tables = [
            'thong_ke_thi_truong' => $wpdb->prefix . 'lcni_thong_ke_thi_truong',
            'ohlc_latest' => $wpdb->prefix . 'lcni_ohlc_latest',
            'money_flow' => $wpdb->prefix . 'lcni_money_flow',
            'stock_stats' => $wpdb->prefix . 'lcni_stock_stats',
        ];

        if (!isset($tables[$source_key])) {
            return [];
        }

        $table = $tables[$source_key];
        $x_axis = sanitize_key((string) ($config['xAxis'] ?? 'event_time'));
        $series = is_array($config['series'] ?? null) ? $config['series'] : [];
        $fields = [$x_axis];

        foreach ($series as $item) {
            $field = sanitize_key((string) ($item['field'] ?? ''));
            if ($field !== '') {
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

        $where = [];
        $params = [];
        if (!empty($config['market'])) {
            $where[] = 'market = %s';
            $params[] = sanitize_text_field((string) $config['market']);
        }
        if (!empty($config['timeframe'])) {
            $where[] = 'timeframe = %s';
            $params[] = sanitize_text_field((string) $config['timeframe']);
        }

        $where_sql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));
        $sql = "SELECT " . implode(', ', $safe_fields) . " FROM {$table}{$where_sql} ORDER BY `{$x_axis}` ASC LIMIT 300";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }
}
