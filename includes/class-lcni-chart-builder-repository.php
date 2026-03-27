<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Chart_Builder_Repository {

    private static function get_join_key_candidates() {
        return ['symbol', 'id_icb2', 'icbid', 'industry_code', 'stock_code'];
    }

    private static function normalize_field_token($raw_field) {
        $raw = (string) $raw_field;
        if (strpos($raw, '.') !== false) {
            [$source_key, $column] = array_pad(explode('.', $raw, 2), 2, '');
            $source_key = sanitize_key($source_key);
            $column = sanitize_key($column);
            if ($source_key !== '' && $column !== '') {
                return $source_key . '.' . $column;
            }
        }

        return sanitize_key($raw);
    }

    private static function to_query_key_suffix($field_token) {
        return str_replace('.', '__', self::normalize_field_token($field_token));
    }

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
            'ohlc' => $wpdb->prefix . 'lcni_ohlc',
            'ohlc_latest' => $wpdb->prefix . 'lcni_ohlc_latest',
            'thong_ke_thi_truong' => $wpdb->prefix . 'lcni_thong_ke_thi_truong',
            'thong_ke_nganh_icb_2' => $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2',
            'thong_ke_nganh_icb_2_toan_thi_truong' => $wpdb->prefix . 'lcni_thong_ke_nganh_icb_2_toan_thi_truong',
            'industry_return' => $wpdb->prefix . 'lcni_industry_return',
            'industry_index' => $wpdb->prefix . 'lcni_industry_index',
            'industry_metrics' => $wpdb->prefix . 'lcni_industry_metrics',
            'recommend_performance' => $wpdb->prefix . 'lcni_recommend_performance',
            'recommend_rule' => $wpdb->prefix . 'lcni_recommend_rule',
            'recommend_signal' => $wpdb->prefix . 'lcni_recommend_signal',
            'money_flow' => $wpdb->prefix . 'lcni_money_flow',
            'stock_stats' => $wpdb->prefix . 'lcni_stock_stats',
        ];
    }

    private static function get_table_name_by_key($table_key) {
        $allowed = self::get_allowed_tables();

        return $allowed[$table_key] ?? '';
    }

    private static function get_joinable_tables($base_table_key) {
        $base_columns = self::get_table_columns($base_table_key);
        if (empty($base_columns)) {
            return [];
        }

        $joinable = [];
        foreach (self::get_allowed_tables() as $table_key => $table_name) {
            if ($table_key === $base_table_key) {
                continue;
            }

            $columns = self::get_table_columns($table_key);
            if (empty($columns)) {
                continue;
            }

            $join_key = '';
            foreach (self::get_join_key_candidates() as $candidate) {
                if (in_array($candidate, $base_columns, true) && in_array($candidate, $columns, true)) {
                    $join_key = $candidate;
                    break;
                }
            }

            if ($join_key === '') {
                continue;
            }

            $joinable[$table_key] = [
                'table' => $table_name,
                'columns' => $columns,
                'join_key' => $join_key,
            ];
        }

        return $joinable;
    }

    private static function build_field_context($base_table_key) {
        $base_table = self::get_table_name_by_key($base_table_key);
        if ($base_table === '') {
            return [];
        }

        $base_columns = self::get_table_columns($base_table_key);
        if (empty($base_columns)) {
            return [];
        }

        $context = [
            'base' => [
                'key' => $base_table_key,
                'table' => $base_table,
                'alias' => 't0',
                'columns' => $base_columns,
            ],
            'fields' => [],
            'joins' => [],
        ];

        foreach ($base_columns as $column) {
            $context['fields'][$column] = 't0.`' . $column . '`';
        }

        $joinable = self::get_joinable_tables($base_table_key);
        $join_index = 1;
        foreach ($joinable as $table_key => $meta) {
            $alias = 't' . $join_index;
            $join_index++;
            $context['joins'][$table_key] = [
                'alias' => $alias,
                'table' => $meta['table'],
                'join_key' => $meta['join_key'],
            ];

            foreach ($meta['columns'] as $column) {
                $token = $table_key . '.' . $column;
                $context['fields'][$token] = $alias . '.`' . $column . '`';
            }
        }

        return $context;
    }

    private static function quote_field_alias($field_token) {
        $alias = str_replace('.', '__', self::normalize_field_token($field_token));

        return '`' . $alias . '`';
    }

    private static function build_required_joins($field_context, $field_tokens) {
        $joins = [];
        foreach ((array) $field_tokens as $token) {
            $normalized = self::normalize_field_token($token);
            if (strpos($normalized, '.') === false) {
                continue;
            }

            $source_key = sanitize_key((string) strstr($normalized, '.', true));
            if ($source_key === '' || !isset($field_context['joins'][$source_key])) {
                continue;
            }

            if (isset($joins[$source_key])) {
                continue;
            }

            $join_meta = $field_context['joins'][$source_key];
            $joins[$source_key] = ' LEFT JOIN ' . $join_meta['table'] . ' ' . $join_meta['alias']
                . ' ON ' . $join_meta['alias'] . '.`' . $join_meta['join_key'] . '` = '
                . $field_context['base']['alias'] . '.`' . $join_meta['join_key'] . '`';
        }

        return implode('', $joins);
    }

    public static function get_table_columns($table_key, $include_joined = false) {
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

        $columns = is_array($columns) ? array_values(array_filter(array_map('sanitize_key', $columns))) : [];
        if (!$include_joined) {
            return $columns;
        }

        $field_context = self::build_field_context($table_key);
        if (empty($field_context['fields'])) {
            return $columns;
        }

        return array_values(array_unique(array_keys($field_context['fields'])));
    }

    public static function get_distinct_field_values($table_key, $field, $limit = 100) {
        global $wpdb;

        $table = self::get_table_name_by_key(sanitize_key((string) $table_key));
        $safe_field = self::normalize_field_token((string) $field);
        if ($table === '' || $safe_field === '') {
            return [];
        }

        $field_context = self::build_field_context(sanitize_key((string) $table_key));
        if (empty($field_context) || !isset($field_context['fields'][$safe_field])) {
            return [];
        }

        $safe_limit = max(1, min(300, (int) $limit));
        $field_sql = $field_context['fields'][$safe_field];
        $join_sql = self::build_required_joins($field_context, [$safe_field]);
        $sql = 'SELECT DISTINCT ' . $field_sql . ' AS v FROM ' . $table . ' ' . $field_context['base']['alias']
            . $join_sql
            . ' WHERE ' . $field_sql . " IS NOT NULL AND " . $field_sql . " <> ''"
            . ' ORDER BY ' . $field_sql . ' ASC LIMIT ' . $safe_limit;
        $values = $wpdb->get_col($sql);

        return array_values(array_filter(array_map(static function ($value) {
            return sanitize_text_field((string) $value);
        }, (array) $values), static function ($value) {
            return $value !== '';
        }));
    }

    private static function build_where_sql($config, $field_context) {
        $where = [];
        $params = [];

        $filters = isset($config['filters']) && is_array($config['filters']) ? $config['filters'] : [];
        $filter_defaults = isset($config['filter_values']) && is_array($config['filter_values']) ? $config['filter_values'] : [];
        foreach ($filters as $filter_field) {
            $field = self::normalize_field_token($filter_field);
            if ($field === '' || !isset($field_context['fields'][$field])) {
                continue;
            }

            $query_key = 'lcni_filter_' . self::to_query_key_suffix($field);
            $raw_values = [];

            if (isset($_GET[$query_key])) {
                $incoming = wp_unslash($_GET[$query_key]);
                if (is_array($incoming)) {
                    $raw_values = $incoming;
                } else {
                    $raw_values = explode(',', (string) $incoming);
                }
            } elseif (isset($filter_defaults[$field]) && is_array($filter_defaults[$field])) {
                $raw_values = $filter_defaults[$field];
            } elseif (isset($filter_defaults[self::to_query_key_suffix($field)]) && is_array($filter_defaults[self::to_query_key_suffix($field)])) {
                $raw_values = $filter_defaults[self::to_query_key_suffix($field)];
            }

            $values = array_values(array_filter(array_map(static function ($value) {
                return sanitize_text_field((string) $value);
            }, (array) $raw_values), static function ($value) {
                return $value !== '';
            }));

            if (empty($values)) {
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($values), '%s'));
            $where[] = $field_context['fields'][$field] . " IN ({$placeholders})";
            $params = array_merge($params, $values);
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

        $field_context = self::build_field_context($source_key);
        if (empty($field_context)) {
            return [];
        }

        $x_axis = self::normalize_field_token((string) ($config['xAxis'] ?? 'event_time'));
        if ($x_axis === '' || !isset($field_context['fields'][$x_axis])) {
            $x_axis = 'event_time';
        }

        $series = is_array($config['series'] ?? null) ? $config['series'] : [];
        $fields = [$x_axis];

        $y_axis = self::normalize_field_token((string) ($config['yAxis'] ?? ''));
        if ($y_axis !== '' && isset($field_context['fields'][$y_axis])) {
            $fields[] = $y_axis;
        }

        foreach ($series as $item) {
            $field = self::normalize_field_token((string) ($item['field'] ?? ''));
            if ($field !== '' && isset($field_context['fields'][$field])) {
                $fields[] = $field;
            }
        }

        $fields = array_values(array_unique(array_filter($fields)));
        if (empty($fields)) {
            return [];
        }

        $safe_fields = [];
        foreach ($fields as $field) {
            if (!isset($field_context['fields'][$field])) {
                continue;
            }
            $safe_fields[] = $field_context['fields'][$field] . ' AS ' . self::quote_field_alias($field);
        }

        if (empty($safe_fields)) {
            return [];
        }

        $where_data = self::build_where_sql(is_array($config) ? $config : [], $field_context);
        $join_sql = self::build_required_joins($field_context, array_merge($fields, (array) ($config['filters'] ?? [])));
        $fallback_order = isset($field_context['base']['columns'][0])
            ? ($field_context['base']['alias'] . '.`' . $field_context['base']['columns'][0] . '`')
            : $field_context['base']['alias'] . '.`id`';
        $order_field = isset($field_context['fields'][$x_axis]) ? $field_context['fields'][$x_axis] : $fallback_order;
        $sql = 'SELECT ' . implode(', ', $safe_fields)
            . ' FROM ' . $table . ' ' . $field_context['base']['alias']
            . $join_sql
            . $where_data['sql']
            . ' ORDER BY ' . $order_field . ' ASC LIMIT 300';

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

        $field_context = self::build_field_context($source_key);
        if (empty($field_context)) {
            return [];
        }

        $result = [];
        foreach ((array) $filter_fields as $raw_field) {
            $field = self::normalize_field_token((string) $raw_field);
            if ($field === '' || !isset($field_context['fields'][$field])) {
                continue;
            }

            $join_sql = self::build_required_joins($field_context, [$field]);
            $field_sql = $field_context['fields'][$field];
            $sql = 'SELECT DISTINCT ' . $field_sql . ' AS v FROM ' . $table . ' ' . $field_context['base']['alias']
                . $join_sql
                . ' WHERE ' . $field_sql . " IS NOT NULL AND " . $field_sql . " <> ''"
                . ' ORDER BY ' . $field_sql . ' ASC LIMIT 100';
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
