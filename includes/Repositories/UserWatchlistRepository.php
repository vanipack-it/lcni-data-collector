<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_UserWatchlistRepository {

    private $wpdb;
    private $watchlist_table;
    private $ohlc_table;
    private $overview_table;

    public function __construct($wpdb) {
        $this->wpdb = $wpdb;
        $this->watchlist_table = $wpdb->prefix . 'lcni_user_watchlist';
        $this->ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $this->overview_table = $wpdb->prefix . 'lcni_symbol_tongquan';
    }

    public function add_symbol($user_id, $symbol, $source = 'manual') {
        return $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$this->watchlist_table} (user_id, symbol, source) VALUES (%d, %s, %s)
                 ON DUPLICATE KEY UPDATE source = VALUES(source), updated_at = CURRENT_TIMESTAMP",
                $user_id,
                $symbol,
                $source
            )
        );
    }

    public function remove_symbol($user_id, $symbol) {
        return $this->wpdb->delete($this->watchlist_table, ['user_id' => $user_id, 'symbol' => $symbol], ['%d', '%s']);
    }

    public function get_symbols($user_id) {
        $sql = $this->wpdb->prepare("SELECT symbol FROM {$this->watchlist_table} WHERE user_id = %d ORDER BY created_at DESC", $user_id);

        return $this->wpdb->get_col($sql);
    }

    public function get_watchlist_rows($user_id, $fields) {
        $field_sql = ['w.symbol'];
        $field_map = $this->get_supported_columns();

        foreach ($fields as $field) {
            if ($field === 'symbol' || !isset($field_map[$field])) {
                continue;
            }
            $field_sql[] = $field_map[$field] . ' AS ' . $field;
        }

        $select = implode(', ', array_unique($field_sql));

        $sql = $this->wpdb->prepare(
            "SELECT {$select}
            FROM {$this->watchlist_table} w
            LEFT JOIN (
                SELECT o1.*
                FROM {$this->ohlc_table} o1
                INNER JOIN (
                    SELECT symbol, MAX(event_time) AS latest_event_time
                    FROM {$this->ohlc_table}
                    WHERE timeframe = 'D'
                    GROUP BY symbol
                ) latest
                ON latest.symbol = o1.symbol AND latest.latest_event_time = o1.event_time
                WHERE o1.timeframe = 'D'
            ) o ON o.symbol = w.symbol
            LEFT JOIN {$this->overview_table} t ON t.symbol = w.symbol
            WHERE w.user_id = %d
            ORDER BY w.created_at DESC",
            $user_id
        );

        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    public function get_supported_columns() {
        return [
            'close_price' => 'o.close_price',
            'volume' => 'o.volume',
            'pct_t_1' => 'o.pct_t_1',
            'pct_1w' => 'o.pct_1w',
            'ma20' => 'o.ma20',
            'rsi' => 'o.rsi',
            'macd_signal' => 'o.macd_signal',
            'xay_nen' => 'o.xay_nen',
            'pha_nen' => 'o.pha_nen',
            'tang_gia_kem_vol' => 'o.tang_gia_kem_vol',
            'rs_exchange_recommend' => 'o.rs_exchange_recommend',
            'pe_ratio' => 't.pe_ratio',
            'pb_ratio' => 't.pb_ratio',
            'roe' => 't.roe',
            'tcbs_khuyen_nghi' => 't.tcbs_khuyen_nghi',
        ];
    }
}
