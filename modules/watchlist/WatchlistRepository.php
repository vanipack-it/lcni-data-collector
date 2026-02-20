<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistRepository {

    private $wpdb;
    private $ohlc_latest_table;
    private $tongquan_table;
    private $market_table;
    private $column_map_cache = null;

    public function __construct($db = null) {
        global $wpdb;

        $this->wpdb = $db ?: $wpdb;
        $this->ohlc_latest_table = $this->wpdb->prefix . 'lcni_ohlc_latest';
        $this->tongquan_table = $this->wpdb->prefix . 'lcni_symbol_tongquan';
        $this->market_table = $this->wpdb->prefix . 'lcni_sym_icb_market';
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
}
