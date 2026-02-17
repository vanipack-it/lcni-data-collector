<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Data_StockRepository {

    public function getLatestBySymbol($symbol) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT symbol, timeframe, event_time, open_price, high_price, low_price, close_price, volume,
                        pct_t_1, ma10, ma20, ma50, ma100, ma200, rsi, xay_nen, pha_nen, tang_gia_kem_vol, smart_money
                 FROM {$table}
                 WHERE symbol = %s AND timeframe = '1D'
                 ORDER BY event_time DESC
                 LIMIT 1",
                $symbol
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function getHistoryBySymbol($symbol, $limit) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_time, open_price, high_price, low_price, close_price, volume
                 FROM {$table}
                 WHERE symbol = %s AND timeframe = '1D'
                 ORDER BY event_time DESC
                 LIMIT %d",
                $symbol,
                (int) $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function getStocks($page, $per_page) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $symbols_table = $wpdb->prefix . 'lcni_symbols';
        $offset = max(0, ($page - 1) * $per_page);
        $latest_time = (int) $wpdb->get_var("SELECT MAX(event_time) FROM {$table} WHERE timeframe = '1D'");

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table}
                 WHERE timeframe = '1D' AND event_time = %d",
                $latest_time
            )
        );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.symbol, s.market_id, o.close_price, o.pct_t_1, o.volume
                 FROM {$table} o
                 LEFT JOIN {$symbols_table} s ON s.symbol = o.symbol
                 WHERE o.timeframe = '1D' AND o.event_time = %d
                 ORDER BY o.value_traded DESC
                 LIMIT %d OFFSET %d",
                $latest_time,
                (int) $per_page,
                (int) $offset
            ),
            ARRAY_A
        );

        return [
            'total' => $total,
            'items' => is_array($items) ? $items : [],
        ];
    }
}
