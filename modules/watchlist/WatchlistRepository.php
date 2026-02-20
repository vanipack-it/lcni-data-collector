<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistRepository {

    private $wpdb;
    private $ohlc_latest_table;
    private $tongquan_table;
    private $market_table;
    private $icb2_table;
    private $column_map_cache = null;

    public function __construct($db = null) {
        global $wpdb;

        $this->wpdb = $db ?: $wpdb;
        $this->ohlc_latest_table = $this->wpdb->prefix . 'lcni_ohlc_latest';
        $this->tongquan_table = $this->wpdb->prefix . 'lcni_symbol_tongquan';
        $this->market_table = $this->wpdb->prefix . 'lcni_sym_icb_market';
        $this->icb2_table = $this->wpdb->prefix . 'lcni_icb2';
    }

    public function get_by_symbols($symbols, $columns) {
        $normalized_symbols = array_values(array_filter(array_map(static function ($symbol) {
            return strtoupper(sanitize_text_field((string) $symbol));
        }, (array) $symbols)));
        if (empty($normalized_symbols)) {
            return [];
        }

        $select_columns = $this->build_select_columns($columns);
        $placeholders = implode(',', array_fill(0, count($normalized_symbols), '%s'));

        $query = $this->wpdb->prepare(
            "SELECT {$select_columns}
            FROM {$this->ohlc_latest_table} o
            LEFT JOIN {$this->tongquan_table} t ON t.symbol = o.symbol
            LEFT JOIN {$this->market_table} m ON m.symbol = o.symbol
            LEFT JOIN {$this->icb2_table} i ON i.id_icb2 = m.id_icb2
            WHERE o.symbol IN ({$placeholders})",
            $normalized_symbols
        );

        $rows = $this->wpdb->get_results($query, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $indexed = [];
        foreach ($rows as $row) {
            $key = strtoupper((string) ($row['symbol'] ?? ''));
            if ($key !== '') {
                $indexed[$key] = $row;
            }
        }

        $ordered = [];
        foreach ($normalized_symbols as $symbol) {
            $symbol = strtoupper((string) $symbol);
            if (isset($indexed[$symbol])) {
                $ordered[] = $indexed[$symbol];
            }
        }

        return $ordered;
    }

    public function get_available_columns() {
        return array_keys($this->get_column_map());
    }

    public function get_all($columns, $limit = 50, $offset = 0, $filters = []) {
        $select_columns = $this->build_select_columns($columns);
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);
        $params = [];
        $where_sql = $this->build_filter_where_clause($filters, $params);

        $query = "SELECT {$select_columns}
            FROM {$this->ohlc_latest_table} o
            LEFT JOIN {$this->tongquan_table} t ON t.symbol = o.symbol
            LEFT JOIN {$this->market_table} m ON m.symbol = o.symbol
            LEFT JOIN {$this->icb2_table} i ON i.id_icb2 = m.id_icb2
            {$where_sql}
            ORDER BY o.symbol ASC
            LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $prepared = $this->wpdb->prepare($query, $params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function count_all($filters = []) {
        $params = [];
        $where_sql = $this->build_filter_where_clause($filters, $params);
        $query = "SELECT COUNT(*)
            FROM {$this->ohlc_latest_table} o
            LEFT JOIN {$this->tongquan_table} t ON t.symbol = o.symbol
            LEFT JOIN {$this->market_table} m ON m.symbol = o.symbol
            LEFT JOIN {$this->icb2_table} i ON i.id_icb2 = m.id_icb2
            {$where_sql}";

        $prepared = empty($params) ? $query : $this->wpdb->prepare($query, $params);
        $count = $this->wpdb->get_var($prepared);

        return max(0, (int) $count);
    }

    public function get_distinct_values($column, $filters = [], $limit = 150) {
        $map = $this->get_column_map();
        if (!isset($map[$column])) {
            return [];
        }

        $params = [];
        $where_sql = $this->build_filter_where_clause($filters, $params);
        $field = $map[$column];
        if ($where_sql === '') {
            $where_sql = 'WHERE';
        } else {
            $where_sql .= ' AND';
        }

        $sql = "SELECT DISTINCT {$field} AS value
            FROM {$this->ohlc_latest_table} o
            LEFT JOIN {$this->tongquan_table} t ON t.symbol = o.symbol
            LEFT JOIN {$this->market_table} m ON m.symbol = o.symbol
            LEFT JOIN {$this->icb2_table} i ON i.id_icb2 = m.id_icb2
            {$where_sql} {$field} IS NOT NULL
            AND {$field} <> ''
            ORDER BY value ASC
            LIMIT %d";

        $params[] = max(10, min(500, (int) $limit));
        $prepared = $this->wpdb->prepare($sql, $params);
        $values = $this->wpdb->get_col($prepared);

        return is_array($values) ? array_values($values) : [];
    }

    private function get_column_map() {
        if (is_array($this->column_map_cache)) {
            return $this->column_map_cache;
        }

        $map = [
            'symbol' => 'o.symbol',
        ];

        $sources = [
            'o' => $this->ohlc_latest_table,
            't' => $this->tongquan_table,
            'm' => $this->market_table,
            'i' => $this->icb2_table,
        ];

        foreach ($sources as $alias => $table_name) {
            $columns = $this->wpdb->get_col("SHOW COLUMNS FROM {$table_name}", 0);
            if (!is_array($columns)) {
                continue;
            }

            foreach ($columns as $column_name) {
                $column_name = sanitize_key((string) $column_name);
                if ($column_name === '' || isset($map[$column_name])) {
                    continue;
                }

                $map[$column_name] = $alias . '.' . $column_name;
            }
        }

        $this->column_map_cache = $map;

        return $map;
    }

    private function build_select_columns($columns) {
        $map = $this->get_column_map();
        $selected = [];

        foreach ($columns as $column) {
            if (!isset($map[$column])) {
                continue;
            }
            $selected[] = $map[$column] . ' AS ' . $column;
        }

        if (empty($selected)) {
            $selected = ['o.symbol AS symbol'];
        }

        return implode(', ', $selected);
    }

    private function build_filter_where_clause($filters, &$params) {
        $map = $this->get_column_map();
        $clauses = [];
        $allowed_operators = ['=', '>', '<', '>=', '<=', 'between', 'contains', 'in'];

        foreach ((array) $filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $column = sanitize_key($filter['column'] ?? '');
            $operator = sanitize_text_field((string) ($filter['operator'] ?? ''));
            $value = $filter['value'] ?? '';

            if (!isset($map[$column]) || !in_array($operator, $allowed_operators, true)) {
                continue;
            }

            $field = $map[$column];
            if ($operator === 'between') {
                $range = is_array($value) ? array_values($value) : [];
                if (count($range) < 2) {
                    continue;
                }
                $clauses[] = "{$field} BETWEEN %s AND %s";
                $params[] = sanitize_text_field((string) $range[0]);
                $params[] = sanitize_text_field((string) $range[1]);
            } elseif ($operator === 'contains') {
                $needle = sanitize_text_field((string) $value);
                if ($needle === '') {
                    continue;
                }
                $clauses[] = "{$field} LIKE %s";
                $params[] = '%' . $this->wpdb->esc_like($needle) . '%';
            } elseif ($operator === 'in') {
                $items = is_array($value) ? array_filter(array_map('sanitize_text_field', $value)) : [];
                if (empty($items)) {
                    continue;
                }
                $ph = implode(',', array_fill(0, count($items), '%s'));
                $clauses[] = "{$field} IN ({$ph})";
                foreach ($items as $item) {
                    $params[] = $item;
                }
            } else {
                $compare = sanitize_text_field((string) $value);
                if ($compare === '') {
                    continue;
                }
                $clauses[] = "{$field} {$operator} %s";
                $params[] = $compare;
            }
        }

        if (empty($clauses)) {
            return '';
        }

        return 'WHERE ' . implode(' AND ', $clauses);
    }
}
