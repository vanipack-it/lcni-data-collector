<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_DB {

    const SYMBOL_BATCH_LIMIT = 50;

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $security_definition_table = $wpdb->prefix . 'lcni_security_definition';
        $log_table = $wpdb->prefix . 'lcni_change_logs';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_ohlc = "CREATE TABLE {$ohlc_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            symbol VARCHAR(20) NOT NULL,
            timeframe VARCHAR(10) NOT NULL,
            event_time BIGINT UNSIGNED NOT NULL,
            open_price DECIMAL(20,6) NOT NULL,
            high_price DECIMAL(20,6) NOT NULL,
            low_price DECIMAL(20,6) NOT NULL,
            close_price DECIMAL(20,6) NOT NULL,
            volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
            value_traded DECIMAL(24,4) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_ohlc (symbol, timeframe, event_time),
            KEY idx_symbol_timeframe (symbol, timeframe),
            KEY idx_event_time (event_time)
        ) {$charset_collate};";

        $sql_security_definition = "CREATE TABLE {$security_definition_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            symbol VARCHAR(20) NOT NULL,
            exchange VARCHAR(20) DEFAULT NULL,
            security_type VARCHAR(30) DEFAULT NULL,
            market VARCHAR(30) DEFAULT NULL,
            reference_price DECIMAL(20,6) DEFAULT NULL,
            ceiling_price DECIMAL(20,6) DEFAULT NULL,
            floor_price DECIMAL(20,6) DEFAULT NULL,
            lot_size INT UNSIGNED DEFAULT NULL,
            listed_volume BIGINT UNSIGNED DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_symbol (symbol),
            KEY idx_exchange (exchange)
        ) {$charset_collate};";

        $sql_change_logs = "CREATE TABLE {$log_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_action (action),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql_ohlc);
        dbDelta($sql_security_definition);
        dbDelta($sql_change_logs);

        self::log_change('activation', 'Created/updated OHLC, security definition and change log tables.');
    }

    public static function collect_all_data($latest_only = false, $offset = 0, $batch_limit = self::SYMBOL_BATCH_LIMIT) {
        $security_summary = self::collect_security_definitions();
        $ohlc_summary = self::collect_ohlc_data($latest_only, $offset, $batch_limit);

        return [
            'security' => $security_summary,
            'ohlc' => $ohlc_summary,
        ];
    }

    public static function collect_security_definitions() {
        $payload = LCNI_API::get_security_definitions();

        if (!is_array($payload)) {
            $error_message = LCNI_API::get_last_request_error();
            $log_message = 'Unable to collect security definitions: invalid payload.';

            if ($error_message !== '') {
                $log_message .= ' ' . $error_message;
            }

            self::log_change('sync_failed', $log_message);

            return new WP_Error(
                'invalid_payload',
                $error_message !== '' ? $error_message : 'Invalid security definitions payload.'
            );
        }

        $rows = self::extract_items($payload, ['data', 'items', 'secDefs', 'secdefs', 'securities', 'symbols']);

        if (empty($rows)) {
            self::log_change('sync_skipped', 'No security definitions returned from DNSE API.');

            return new WP_Error('empty_payload', 'Security definitions payload is empty.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lcni_security_definition';

        $updated = 0;
        foreach ($rows as $row) {
            $symbol = strtoupper((string) self::pick($row, ['symbol', 's']));
            if ($symbol === '') {
                continue;
            }

            $record = [
                'symbol' => $symbol,
                'exchange' => self::nullable_text(self::pick($row, ['exchange', 'ex', 'exchangeCode'])),
                'security_type' => self::nullable_text(self::pick($row, ['securityType', 'security_type', 'type', 'secType'])),
                'market' => self::nullable_text(self::pick($row, ['market', 'mkt', 'marketType'])),
                'reference_price' => self::nullable_float(self::pick($row, ['referencePrice', 'reference_price', 'refPrice'])),
                'ceiling_price' => self::nullable_float(self::pick($row, ['ceilingPrice', 'ceiling_price', 'ceilPrice'])),
                'floor_price' => self::nullable_float(self::pick($row, ['floorPrice', 'floor_price'])),
                'lot_size' => self::nullable_int(self::pick($row, ['lotSize', 'lot_size'])),
                'listed_volume' => self::nullable_int(self::pick($row, ['listedVolume', 'listed_volume'])),
            ];

            $query = $wpdb->prepare(
                "INSERT INTO {$table}
                (symbol, exchange, security_type, market, reference_price, ceiling_price, floor_price, lot_size, listed_volume)
                VALUES (%s, %s, %s, %s, %f, %f, %f, %d, %d)
                ON DUPLICATE KEY UPDATE
                    exchange = VALUES(exchange),
                    security_type = VALUES(security_type),
                    market = VALUES(market),
                    reference_price = VALUES(reference_price),
                    ceiling_price = VALUES(ceiling_price),
                    floor_price = VALUES(floor_price),
                    lot_size = VALUES(lot_size),
                    listed_volume = VALUES(listed_volume)",
                $record['symbol'],
                $record['exchange'],
                $record['security_type'],
                $record['market'],
                (float) $record['reference_price'],
                (float) $record['ceiling_price'],
                (float) $record['floor_price'],
                (int) $record['lot_size'],
                (int) $record['listed_volume']
            );

            $result = $wpdb->query($query);
            if ($result !== false) {
                $updated++;
            }
        }

        self::log_change('sync_security_definition', sprintf('Upserted %d security definitions.', $updated));

        return [
            'updated' => $updated,
            'total' => count($rows),
        ];
    }

    public static function collect_ohlc_data($latest_only = false, $offset = 0, $batch_limit = self::SYMBOL_BATCH_LIMIT) {
        global $wpdb;

        $timeframe = strtoupper((string) get_option('lcni_timeframe', '1D'));
        $days = max(1, (int) get_option('lcni_days_to_load', 365));

        $batch_limit = max(1, (int) $batch_limit);
        $offset = max(0, (int) $offset);

        $total_symbols = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lcni_security_definition");

        $symbols = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT symbol FROM {$wpdb->prefix}lcni_security_definition WHERE exchange IN ('HOSE', 'HNX') ORDER BY symbol ASC LIMIT %d OFFSET %d",
                $batch_limit,
                $offset
            )
        );

        if (empty($symbols)) {
            $symbols = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT symbol FROM {$wpdb->prefix}lcni_security_definition ORDER BY symbol ASC LIMIT %d OFFSET %d",
                    $batch_limit,
                    $offset
                )
            );
        }

        if (empty($symbols)) {
            self::log_change('sync_skipped', 'No symbols available in security definition table for OHLC sync.');

            return [
                'inserted' => 0,
                'updated' => 0,
                'processed_symbols' => 0,
                'total_symbols' => $total_symbols,
                'next_offset' => $offset,
                'has_more' => false,
            ];
        }

        $table = $wpdb->prefix . 'lcni_ohlc';
        $inserted = 0;
        $updated = 0;
        $processed_symbols = 0;

        foreach ($symbols as $symbol) {
            $symbol = strtoupper((string) $symbol);

            if ($latest_only) {
                $latest_event_time = self::get_latest_event_time($symbol, $timeframe);

                if ($latest_event_time > 0) {
                    $from_timestamp = max(1, $latest_event_time - DAY_IN_SECONDS);
                    $to_timestamp = time();
                    $payload = LCNI_API::get_candles_by_range($symbol, $timeframe, $from_timestamp, $to_timestamp);
                } else {
                    // Lần đầu chưa có dữ liệu trong DB thì fallback về cách lấy theo số ngày,
                    // tránh gọi by_range với from=0 khiến API trả lỗi.
                    $payload = LCNI_API::get_candles($symbol, $timeframe, $days);
                }
            } else {
                $payload = LCNI_API::get_candles($symbol, $timeframe, $days);
            }

            if (!is_array($payload)) {
                self::log_change('sync_symbol_failed', sprintf('OHLC request failed for symbol=%s timeframe=%s latest_only=%s.', $symbol, $timeframe, $latest_only ? 'yes' : 'no'));
                continue;
            }

            $processed_symbols++;

            $rows = lcni_convert_candles($payload, $symbol, $timeframe);
            if ($latest_only && !empty($rows)) {
                $latest_db_event_time = self::get_latest_event_time($symbol, $timeframe);
                $rows = array_filter(
                    $rows,
                    static function ($row) use ($latest_db_event_time) {
                        $event_time = strtotime($row['candle_time']);

                        return $event_time !== false && $event_time > $latest_db_event_time;
                    }
                );
            }

            foreach ($rows as $row) {
                $event_time = strtotime($row['candle_time']);
                if ($event_time === false || $event_time <= 0) {
                    continue;
                }

                $record = [
                    'symbol' => $row['symbol'],
                    'timeframe' => $row['timeframe'],
                    'event_time' => $event_time,
                    'open_price' => (float) $row['open'],
                    'high_price' => (float) $row['high'],
                    'low_price' => (float) $row['low'],
                    'close_price' => (float) $row['close'],
                    'volume' => (int) $row['volume'],
                    'value_traded' => (float) (($row['close'] ?? 0) * ($row['volume'] ?? 0)),
                ];

                $query = $wpdb->prepare(
                    "INSERT INTO {$table}
                    (symbol, timeframe, event_time, open_price, high_price, low_price, close_price, volume, value_traded)
                    VALUES (%s, %s, %d, %f, %f, %f, %f, %d, %f)
                    ON DUPLICATE KEY UPDATE
                        open_price = VALUES(open_price),
                        high_price = VALUES(high_price),
                        low_price = VALUES(low_price),
                        close_price = VALUES(close_price),
                        volume = VALUES(volume),
                        value_traded = VALUES(value_traded)",
                    $record['symbol'],
                    $record['timeframe'],
                    $record['event_time'],
                    $record['open_price'],
                    $record['high_price'],
                    $record['low_price'],
                    $record['close_price'],
                    $record['volume'],
                    $record['value_traded']
                );

                $result = $wpdb->query($query);

                if ($result === 1) {
                    $inserted++;
                } elseif ($result === 2 || $result === 0) {
                    $updated++;
                }
            }
        }

        self::log_change('sync_ohlc', sprintf('OHLC sync done. inserted=%d updated=%d latest_only=%s timeframe=%s days=%d.', $inserted, $updated, $latest_only ? 'yes' : 'no', $timeframe, $days));

        $next_offset = $offset + count($symbols);

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'processed_symbols' => $processed_symbols,
            'total_symbols' => $total_symbols,
            'next_offset' => $next_offset,
            'has_more' => $next_offset < $total_symbols,
            'batch_limit' => $batch_limit,
        ];
    }

    public static function get_latest_event_time($symbol, $timeframe) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $latest_event_time = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(event_time) FROM {$table} WHERE symbol = %s AND timeframe = %s",
                strtoupper((string) $symbol),
                strtoupper((string) $timeframe)
            )
        );

        return max(0, $latest_event_time);
    }

    private static function extract_items($payload, $preferred_keys = []) {
        if (!is_array($payload)) {
            return [];
        }

        if (self::looks_like_security_definition($payload)) {
            return [$payload];
        }

        if (self::is_list_array($payload)) {
            return $payload;
        }

        $keys = array_merge(
            $preferred_keys,
            ['data', 'items', 'rows', 'result', 'results', 'content', 'candles', 'secDefs', 'secdefs']
        );

        foreach (array_unique($keys) as $key) {
            if (!array_key_exists($key, $payload) || !is_array($payload[$key])) {
                continue;
            }

            $items = self::extract_items($payload[$key]);
            if (!empty($items)) {
                return $items;
            }
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $items = self::extract_items($value);
            if (!empty($items)) {
                return $items;
            }
        }

        return [];
    }


    private static function is_list_array($array) {
        if (!is_array($array)) {
            return false;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    private static function pick($row, $keys, $default = null) {
        foreach ($keys as $key) {
            if (isset($row[$key])) {
                return $row[$key];
            }
        }

        return $default;
    }

    private static function nullable_text($value) {
        if ($value === null || $value === '') {
            return null;
        }

        return sanitize_text_field((string) $value);
    }

    private static function nullable_float($value) {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private static function nullable_int($value) {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function looks_like_security_definition($row) {
        if (!is_array($row)) {
            return false;
        }

        $keys = ['symbol', 's', 'exchange', 'securityType', 'referencePrice'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return true;
            }
        }

        return false;
    }

    public static function log_change($action, $message, $context = null) {
        global $wpdb;

        $log_table = $wpdb->prefix . 'lcni_change_logs';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $log_table)) !== $log_table) {
            error_log(sprintf('[LCNI Data Collector] %s: %s', $action, $message));

            return;
        }

        $wpdb->insert(
            $log_table,
            [
                'action' => sanitize_text_field($action),
                'message' => sanitize_textarea_field($message),
                'context' => $context ? wp_json_encode($context) : null,
            ],
            ['%s', '%s', '%s']
        );
    }
}

if (!function_exists('lcni_convert_candles')) {
    function lcni_convert_candles($data, $symbol, $tf) {
        $rows = [];

        if (!is_array($data) || empty($data['t']) || !is_array($data['t'])) {
            return $rows;
        }

        foreach ($data['t'] as $i => $time) {
            $timestamp = (int) $time;
            if ($timestamp > 1000000000000) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            $rows[] = [
                'symbol' => strtoupper((string) $symbol),
                'timeframe' => strtoupper((string) $tf),
                'candle_time' => gmdate('Y-m-d H:i:s', $timestamp),
                'open' => isset($data['o'][$i]) ? (float) $data['o'][$i] : 0,
                'high' => isset($data['h'][$i]) ? (float) $data['h'][$i] : 0,
                'low' => isset($data['l'][$i]) ? (float) $data['l'][$i] : 0,
                'close' => isset($data['c'][$i]) ? (float) $data['c'][$i] : 0,
                'volume' => isset($data['v'][$i]) ? (int) $data['v'][$i] : 0,
            ];
        }

        return $rows;
    }
}
