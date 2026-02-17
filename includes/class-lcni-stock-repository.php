<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_StockRepository {

    const CACHE_GROUP = 'lcni_stock';
    const CACHE_TTL = 60;

    public static function get_stock($symbol) {
        global $wpdb;

        $normalized_symbol = self::normalize_symbol($symbol);
        if ($normalized_symbol === '') {
            return null;
        }

        $cache_key = self::build_cache_key('stock', [$normalized_symbol]);
        $cached = self::get_cached_value($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $symbols_table = $wpdb->prefix . 'lcni_symbols';
        $mapping_table = $wpdb->prefix . 'lcni_sym_icb_market';
        $market_table = $wpdb->prefix . 'lcni_marketid';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT o.*, s.market_id,
                        UPPER(TRIM(COALESCE(map.exchange, m.exchange, ''))) AS exchange
                FROM {$ohlc_table} o
                LEFT JOIN {$symbols_table} s ON s.symbol = o.symbol
                LEFT JOIN {$mapping_table} map ON map.symbol = o.symbol
                LEFT JOIN {$market_table} m ON m.market_id = s.market_id
                WHERE o.symbol = %s AND o.timeframe = '1D'
                ORDER BY o.event_time DESC
                LIMIT 1",
                $normalized_symbol
            ),
            ARRAY_A
        );

        if (empty($row)) {
            return null;
        }

        $row['symbol'] = $normalized_symbol;
        self::set_cached_value($cache_key, $row);

        return $row;
    }

    public static function get_stock_history($symbol, $limit = 120, $timeframe = '1D') {
        global $wpdb;

        $normalized_symbol = self::normalize_symbol($symbol);
        $normalized_timeframe = strtoupper(sanitize_text_field((string) $timeframe));
        $normalized_limit = max(1, min(500, (int) $limit));

        if ($normalized_symbol === '' || $normalized_timeframe === '') {
            return [];
        }

        $cache_key = self::build_cache_key('history', [$normalized_symbol, $normalized_timeframe, $normalized_limit]);
        $cached = self::get_cached_value($cache_key);
        if ($cached !== null) {
            return $cached;
        }

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT symbol, timeframe, event_time, open_price, high_price, low_price, close_price, volume, value_traded,
                        pct_t_1, pct_t_3, pct_1w, pct_1m, pct_3m, pct_6m, pct_1y, ma10, ma20, ma50, ma100, ma200, rsi,
                        created_at
                FROM {$ohlc_table}
                WHERE symbol = %s AND timeframe = %s
                ORDER BY event_time DESC
                LIMIT %d",
                $normalized_symbol,
                $normalized_timeframe,
                $normalized_limit
            ),
            ARRAY_A
        );

        self::set_cached_value($cache_key, $rows);

        return $rows;
    }

    private static function normalize_symbol($symbol) {
        return strtoupper(sanitize_text_field((string) $symbol));
    }

    private static function build_cache_key($prefix, $parts) {
        return 'lcni:' . $prefix . ':' . md5(wp_json_encode($parts));
    }

    private static function get_cached_value($cache_key) {
        $cache_hit = false;
        $value = wp_cache_get($cache_key, self::CACHE_GROUP, false, $cache_hit);

        if ($cache_hit) {
            return $value;
        }

        $transient_value = get_transient($cache_key);
        if ($transient_value !== false) {
            wp_cache_set($cache_key, $transient_value, self::CACHE_GROUP, self::CACHE_TTL);

            return $transient_value;
        }

        return null;
    }

    private static function set_cached_value($cache_key, $value) {
        wp_cache_set($cache_key, $value, self::CACHE_GROUP, self::CACHE_TTL);
        set_transient($cache_key, $value, self::CACHE_TTL);
    }
}
