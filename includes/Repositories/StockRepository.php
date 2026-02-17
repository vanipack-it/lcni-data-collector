<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Data_StockRepository {

    private function toLightweightBusinessDay($event_time) {
        $timestamp = (int) $event_time;
        if ($timestamp <= 0) {
            return null;
        }

        return gmdate('Y-m-d', $timestamp);
    }

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

    public function getCandlesBySymbol($symbol, $limit = 200, $timeframe = '1D') {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $safe_limit = max(1, min(500, (int) $limit));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT trading_date, event_time, open_price, high_price, low_price, close_price, volume, macd, macd_signal, rsi
                 FROM (
                     SELECT DATE(FROM_UNIXTIME(event_time)) AS trading_date,
                            event_time,
                            open_price,
                            high_price,
                            low_price,
                            close_price,
                            volume,
                            macd,
                            macd_signal,
                            rsi
                     FROM {$table}
                     WHERE symbol = %s AND timeframe = %s
                     ORDER BY event_time DESC
                     LIMIT %d
                 ) candles
                 ORDER BY event_time ASC",
                $symbol,
                $timeframe,
                $safe_limit
            )
        );

        return is_array($rows) ? $rows : [];
    }

    public function getChartDataBySymbol($symbol, $limit = 200) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_stock_prices';
        $safe_limit = max(1, min(2000, (int) $limit));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT time, open, high, low, close, volume
                 FROM (
                     SELECT time, open, high, low, close, volume
                     FROM {$table}
                     WHERE symbol = %s
                     ORDER BY time DESC
                     LIMIT %d
                 ) prices
                 ORDER BY time ASC",
                $symbol,
                $safe_limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }


    public function getDetailPageBySymbol($symbol) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_time, open_price, high_price, low_price, close_price, volume,
                        ma10, ma20, ma50, ma100, ma200, macd, macd_signal, rsi
                FROM {$table}
                WHERE symbol = %s AND timeframe = '1D'
                ORDER BY event_time DESC
                LIMIT %d",
                $symbol,
                250
            ),
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return null;
        }

        $latest = $rows[0];
        $history = array_reverse($rows);

        return [
            'symbol' => $symbol,
            'price_history' => array_map(
                function ($row) {
                    $time = $this->toLightweightBusinessDay($row['event_time']);

                    return [
                        'time' => $time,
                        'value' => (float) $row['close_price'],
                        'price' => (float) $row['close_price'],
                    ];
                },
                $history
            ),
            'ohlc_history' => array_map(
                function ($row) {
                    return [
                        'time' => $this->toLightweightBusinessDay($row['event_time']),
                        'open' => (float) $row['open_price'],
                        'high' => (float) $row['high_price'],
                        'low' => (float) $row['low_price'],
                        'close' => (float) $row['close_price'],
                    ];
                },
                $history
            ),
            'volume_values' => array_map(
                function ($row) {
                    return [
                        'time' => $this->toLightweightBusinessDay($row['event_time']),
                        'volume' => isset($row['volume']) ? (float) $row['volume'] : 0.0,
                    ];
                },
                $history
            ),
            'ma_values' => array_map(
                function ($row) {
                    return [
                        'time' => $this->toLightweightBusinessDay($row['event_time']),
                        'ma10' => $row['ma10'] !== null ? (float) $row['ma10'] : null,
                        'ma20' => $row['ma20'] !== null ? (float) $row['ma20'] : null,
                        'ma50' => $row['ma50'] !== null ? (float) $row['ma50'] : null,
                        'ma100' => $row['ma100'] !== null ? (float) $row['ma100'] : null,
                        'ma200' => $row['ma200'] !== null ? (float) $row['ma200'] : null,
                    ];
                },
                $history
            ),
            'rsi_values' => array_map(
                function ($row) {
                    return [
                        'time' => $this->toLightweightBusinessDay($row['event_time']),
                        'rsi' => $row['rsi'] !== null ? (float) $row['rsi'] : null,
                    ];
                },
                $history
            ),
            'macd_values' => array_map(
                function ($row) {
                    return [
                        'time' => $this->toLightweightBusinessDay($row['event_time']),
                        'macd' => isset($row['macd']) ? (float) $row['macd'] : null,
                        'signal' => isset($row['macd_signal']) ? (float) $row['macd_signal'] : null,
                    ];
                },
                $history
            ),
            'last_updated_time' => (int) $latest['event_time'],
        ];
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
