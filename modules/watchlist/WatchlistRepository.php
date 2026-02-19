<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistRepository {

    private $wpdb;
    private $table;
    private $ohlc_latest_table;
    private $tongquan_table;
    private $market_table;

    public function __construct($db = null) {
        global $wpdb;

        $this->wpdb = $db ?: $wpdb;
        $this->table = $this->wpdb->prefix . 'lcni_watchlist';
        $this->ohlc_latest_table = $this->wpdb->prefix . 'lcni_ohlc_latest';
        $this->tongquan_table = $this->wpdb->prefix . 'lcni_symbol_tongquan';
        $this->market_table = $this->wpdb->prefix . 'lcni_sym_icb_market';
    }

    public function add($user_id, $symbol) {
        return (bool) $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO {$this->table} (user_id, symbol) VALUES (%d, %s)",
                $user_id,
                $symbol
            )
        );
    }

    public function remove($user_id, $symbol) {
        return (bool) $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE user_id = %d AND symbol = %s",
                $user_id,
                $symbol
            )
        );
    }

    public function get_by_user($user_id, $columns) {
        $select_columns = $this->build_select_columns($columns);

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT {$select_columns}
                FROM {$this->table} w
                LEFT JOIN {$this->ohlc_latest_table} o ON o.symbol = w.symbol
                LEFT JOIN {$this->tongquan_table} t ON t.symbol = w.symbol
                LEFT JOIN {$this->market_table} m ON m.symbol = w.symbol
                WHERE w.user_id = %d
                ORDER BY w.created_at DESC",
                $user_id
            ),
            ARRAY_A
        );
    }

    public function get_symbols_by_user($user_id) {
        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare("SELECT symbol FROM {$this->table} WHERE user_id = %d", $user_id)
        );

        return array_values(array_map('strtoupper', (array) $rows));
    }

    private function build_select_columns($columns) {
        $map = [
            'symbol' => 'w.symbol',
            'created_at' => 'w.created_at',
            'close_price' => 'o.close_price',
            'pct_t_1' => 'o.pct_t_1',
            'volume' => 'o.volume',
            'value_traded' => 'o.value_traded',
            'exchange' => 'm.exchange',
            'market_id' => 'm.market_id',
            'eps' => 't.eps',
            'roe' => 't.roe',
            'pe_ratio' => 't.pe_ratio',
            'pb_ratio' => 't.pb_ratio',
            'tc_rating' => 't.tc_rating',
            'xep_hang' => 't.xep_hang',
        ];

        $selected = [];
        foreach ($columns as $column) {
            if (!isset($map[$column])) {
                continue;
            }
            $selected[] = $map[$column] . ' AS ' . $column;
        }

        if (empty($selected)) {
            $selected = ['w.symbol AS symbol'];
        }

        return implode(', ', $selected);
    }
}
