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
                        pct_t_1, ma10, ma20, ma50, ma100, ma200, rsi, xay_nen, pha_nen, tang_gia_kem_vol, smart_money,
                        rs_exchange_status, rs_exchange_recommend
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

    public function getCandlesBySymbol($symbol, $limit = 200) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $safe_limit = max(1, min(500, (int) $limit));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(FROM_UNIXTIME(t.event_time)) AS trading_date,
                        t.event_time,
                        t.open_price,
                        t.high_price,
                        t.low_price,
                        t.close_price,
                        t.volume,
                        t.macd,
                        t.macd_signal,
                        t.rsi,
                        t.rs_1w_by_exchange,
                        t.rs_1m_by_exchange,
                        t.rs_3m_by_exchange
                 FROM (
                    SELECT event_time,
                           open_price,
                           high_price,
                           low_price,
                           close_price,
                           volume,
                           macd,
                           macd_signal,
                           rsi,
                           rs_1w_by_exchange,
                           rs_1m_by_exchange,
                           rs_3m_by_exchange
                    FROM {$table}
                    WHERE symbol = %s AND timeframe = '1D'
                    ORDER BY event_time DESC
                    LIMIT %d
                 ) AS t
                 ORDER BY t.event_time ASC",
                $symbol,
                $safe_limit
            )
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

    public function getLatestSignalsBySymbol($symbol) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc_latest';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT symbol,
                        event_time,
                        xay_nen,
                        xay_nen_count_30,
                        nen_type,
                        pha_nen,
                        tang_gia_kem_vol,
                        smart_money,
                        rs_exchange_status,
                        rs_exchange_recommend,
                        rs_recommend_status
                 FROM {$table}
                 WHERE symbol = %s AND timeframe = '1D'
                 LIMIT 1",
                $symbol
            ),
            ARRAY_A
        );

        return $row ?: null;
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

    public function getOverviewBySymbol($symbol) {
        global $wpdb;

        $tongquan_table = $wpdb->prefix . 'lcni_symbol_tongquan';
        $symbols_table = $wpdb->prefix . 'lcni_symbols';
        $mapping_table = $wpdb->prefix . 'lcni_sym_icb_market';
        $market_table = $wpdb->prefix . 'lcni_marketid';
        $icb2_table = $wpdb->prefix . 'lcni_icb2';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT t.symbol,
                        UPPER(TRIM(COALESCE(map.exchange, m.exchange, ''))) AS exchange,
                        i.name_icb2 AS icb2_name,
                        t.eps,
                        t.eps_1y_pct,
                        t.dt_1y_pct,
                        t.bien_ln_gop,
                        t.bien_ln_rong,
                        t.roe,
                        t.de_ratio,
                        t.pe_ratio,
                        t.pb_ratio,
                        t.ev_ebitda,
                        t.tcbs_khuyen_nghi,
                        t.co_tuc_pct,
                        t.tc_rating,
                        t.so_huu_nn_pct,
                        t.tien_mat_rong_von_hoa,
                        t.tien_mat_rong_tong_tai_san,
                        t.loi_nhuan_4_quy_gan_nhat,
                        t.tang_truong_dt_quy_gan_nhat,
                        t.tang_truong_dt_quy_gan_nhi,
                        t.tang_truong_ln_quy_gan_nhat,
                        t.tang_truong_ln_quy_gan_nhi
                 FROM {$tongquan_table} t
                 LEFT JOIN {$symbols_table} s ON s.symbol = t.symbol
                 LEFT JOIN {$mapping_table} map ON map.symbol = t.symbol
                 LEFT JOIN {$market_table} m ON m.market_id = s.market_id
                 LEFT JOIN {$icb2_table} i ON i.id_icb2 = COALESCE(map.id_icb2, s.id_icb2)
                 WHERE t.symbol = %s
                 LIMIT 1",
                $symbol
            ),
            ARRAY_A
        );

        return $row ?: null;
    }
}
