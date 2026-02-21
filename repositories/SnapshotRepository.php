<?php

if (!defined('ABSPATH')) {
    exit;
}

class SnapshotRepository {
    private $wpdb;
    private $cache;
    private $ohlc_latest_table;
    private $tongquan_table;
    private $market_table;
    private $icb2_table;
    private $column_map_cache = null;

    public function __construct(CacheService $cache = null, $db = null) {
        global $wpdb;

        $this->wpdb = $db ?: $wpdb;
        $this->cache = $cache ?: new CacheService('lcni_filter');
        $this->ohlc_latest_table = $this->wpdb->prefix . 'lcni_ohlc_latest';
        $this->tongquan_table = $this->wpdb->prefix . 'lcni_symbol_tongquan';
        $this->market_table = $this->wpdb->prefix . 'lcni_sym_icb_market';
        $this->icb2_table = $this->wpdb->prefix . 'lcni_icb2';
    }

    public function getFiltered(array $filters, array $columns = [], int $limit = 0, int $offset = 0): array {
        $selected_columns = $this->buildSelectColumns($columns);
        $join_sql = $this->buildJoinSql($selected_columns['aliases'], $filters);

        $params = [];
        $where_sql = $this->buildFilterWhereClause($filters, $params);
        $query = "SELECT {$selected_columns['sql']}\n"
            . "FROM {$this->ohlc_latest_table} o\n"
            . $join_sql
            . "{$where_sql}\n"
            . "ORDER BY o.symbol ASC";

        if ($limit > 0) {
            $query .= "\nLIMIT %d";
            $params[] = $limit;

            if ($offset > 0) {
                $query .= " OFFSET %d";
                $params[] = $offset;
            }
        }

        // TODO: EXPLAIN query in production to verify no full table scan
        $prepared = empty($params) ? $query : $this->wpdb->prepare($query, $params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function countFiltered(array $filters = []): int {
        $join_sql = $this->buildJoinSql([], $filters);
        $params = [];
        $where_sql = $this->buildFilterWhereClause($filters, $params);

        $query = "SELECT COUNT(*)\n"
            . "FROM {$this->ohlc_latest_table} o\n"
            . $join_sql
            . $where_sql;

        $prepared = empty($params) ? $query : $this->wpdb->prepare($query, $params);
        $count = $this->wpdb->get_var($prepared);

        return max(0, (int) $count);
    }

    public function getDistinctValues(string $column): array {
        $normalized_column = sanitize_key($column);
        $cache_key = 'distinct_' . $normalized_column;
        $cached = $this->cache->get($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $map = $this->getColumnMap();
        if (!isset($map[$normalized_column])) {
            return [];
        }

        $field = $map[$normalized_column];
        $join_sql = $this->buildJoinSql([$this->extractAlias($field)], []);
        $query = "SELECT DISTINCT {$field} AS value\n"
            . "FROM {$this->ohlc_latest_table} o\n"
            . $join_sql
            . "WHERE {$field} IS NOT NULL\n"
            . "AND {$field} <> ''\n"
            . "ORDER BY value ASC\n"
            . 'LIMIT 120';

        $values = $this->wpdb->get_col($query);
        $result = is_array($values) ? array_values($values) : [];
        $this->cache->set($cache_key, $result, 300);

        return $result;
    }

    public function getAllSymbols(): array {
        $cached = $this->cache->get('all_symbols');
        if (is_array($cached)) {
            return $cached;
        }

        $query = "SELECT o.symbol\nFROM {$this->ohlc_latest_table} o\nWHERE o.symbol IS NOT NULL AND o.symbol <> ''\nORDER BY o.symbol ASC";
        $symbols = $this->wpdb->get_col($query);
        $result = is_array($symbols) ? array_values($symbols) : [];
        $this->cache->set('all_symbols', $result, 300);

        return $result;
    }

    private function getColumnMap(): array {
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

    private function buildSelectColumns(array $columns): array {
        $map = $this->getColumnMap();
        $selected = [];
        $aliases = [];

        foreach ($columns as $column) {
            if (!isset($map[$column])) {
                continue;
            }
            $field = $map[$column];
            $selected[] = $field . ' AS ' . $column;
            $aliases[] = $this->extractAlias($field);
        }

        if (empty($selected)) {
            $selected[] = 'o.symbol AS symbol';
            $aliases[] = 'o';
        }

        return [
            'sql' => implode(', ', $selected),
            'aliases' => array_values(array_unique($aliases)),
        ];
    }

    private function buildJoinSql(array $selected_aliases, array $filters): string {
        $aliases = array_values(array_unique($selected_aliases));
        $map = $this->getColumnMap();

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $column = sanitize_key($filter['column'] ?? '');
            if ($column === '' || !isset($map[$column])) {
                continue;
            }

            $aliases[] = $this->extractAlias($map[$column]);
        }

        $aliases = array_values(array_unique($aliases));
        if (in_array('i', $aliases, true) && !in_array('m', $aliases, true)) {
            $aliases[] = 'm';
        }

        $join_sql = '';
        if (in_array('t', $aliases, true)) {
            $join_sql .= "LEFT JOIN {$this->tongquan_table} t ON t.symbol = o.symbol\n";
        }
        if (in_array('m', $aliases, true)) {
            $join_sql .= "LEFT JOIN {$this->market_table} m ON m.symbol = o.symbol\n";
        }
        if (in_array('i', $aliases, true)) {
            $join_sql .= "LEFT JOIN {$this->icb2_table} i ON i.id_icb2 = m.id_icb2\n";
        }

        return $join_sql;
    }

    private function buildFilterWhereClause(array $filters, array &$params): string {
        $map = $this->getColumnMap();
        $clauses = [];
        $allowed_operators = ['=', '>', '<', '>=', '<=', 'between', 'contains', 'in'];

        foreach ($filters as $filter) {
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

    private function extractAlias(string $field): string {
        $parts = explode('.', $field);
        return $parts[0] ?? 'o';
    }
}
