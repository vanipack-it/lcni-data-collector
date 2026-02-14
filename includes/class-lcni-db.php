<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_DB {

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

    public static function collect_all_data() {
        self::collect_security_definitions();
        self::collect_ohlc_data();
    }

    public static function collect_security_definitions() {
        $payload = LCNI_API::get_security_definitions();

        if (!is_array($payload)) {
            self::log_change('sync_failed', 'Unable to collect security definitions: invalid payload.');

            return;
        }

        $rows = self::extract_items($payload, ['data', 'items', 'secDefs', 'secdefs', 'securities', 'symbols']);

        if (empty($rows)) {
            self::log_change('sync_skipped', 'No security definitions returned from DNSE API.');

            return;
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
                'exchange' => self::nullable_text(self::pick($row, ['exchange', 'ex'])),
                'security_type' => self::nullable_text(self::pick($row, ['securityType', 'security_type', 'type'])),
                'market' => self::nullable_text(self::pick($row, ['market', 'mkt'])),
                'reference_price' => self::nullable_float(self::pick($row, ['referencePrice', 'reference_price', 'refPrice'])),
                'ceiling_price' => self::nullable_float(self::pick($row, ['ceilingPrice', 'ceiling_price', 'ceilPrice'])),
                'floor_price' => self::nullable_float(self::pick($row, ['floorPrice', 'floor_price'])),
                'lot_size' => self::nullable_int(self::pick($row, ['lotSize', 'lot_size'])),
                'listed_volume' => self::nullable_int(self::pick($row, ['listedVolume', 'listed_volume'])),
            ];

            $exists_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE symbol = %s LIMIT 1", $symbol));
            if ($exists_id) {
                $wpdb->update($table, $record, ['id' => (int) $exists_id]);
            } else {
                $wpdb->insert($table, $record);
            }

            $updated++;
        }

        self::log_change('sync_security_definition', sprintf('Upserted %d security definitions.', $updated));
    }

    public static function collect_ohlc_data() {
        global $wpdb;

        $symbols = $wpdb->get_col("SELECT symbol FROM {$wpdb->prefix}lcni_security_definition ORDER BY symbol ASC LIMIT 30");
        if (empty($symbols)) {
            self::log_change('sync_skipped', 'No symbols available in security definition table for OHLC sync.');

            return;
        }

        $table = $wpdb->prefix . 'lcni_ohlc';
        $inserted = 0;

        foreach ($symbols as $symbol) {
            $payload = LCNI_API::get_candles($symbol, '1D');
            if (!is_array($payload)) {
                continue;
            }

            $candles = self::extract_items($payload, ['data', 'candles', 'items', 'rows']);
            foreach ($candles as $candle) {
                $event_time = (int) self::pick($candle, ['t', 'eventTime', 'event_time', 0]);
                if ($event_time <= 0) {
                    continue;
                }

                if ($event_time > 1000000000000) {
                    $event_time = (int) floor($event_time / 1000);
                }

                $record = [
                    'symbol' => strtoupper($symbol),
                    'timeframe' => '1D',
                    'event_time' => $event_time,
                    'open_price' => (float) self::pick($candle, ['o', 'open', 'openPrice', 1]),
                    'high_price' => (float) self::pick($candle, ['h', 'high', 'highPrice', 2]),
                    'low_price' => (float) self::pick($candle, ['l', 'low', 'lowPrice', 3]),
                    'close_price' => (float) self::pick($candle, ['c', 'close', 'closePrice', 4]),
                    'volume' => (int) self::pick($candle, ['v', 'volume', 5]),
                    'value_traded' => (float) self::pick($candle, ['value', 'valueTraded', 'turnover', 6]),
                ];

                $exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE symbol = %s AND timeframe = %s AND event_time = %d LIMIT 1",
                        $record['symbol'],
                        $record['timeframe'],
                        $record['event_time']
                    )
                );

                if ($exists) {
                    $wpdb->update($table, $record, ['id' => (int) $exists]);
                } else {
                    $wpdb->insert($table, $record);
                    $inserted++;
                }
            }
        }

        self::log_change('sync_ohlc', sprintf('Inserted %d OHLC records.', $inserted));
    }

    private static function extract_items($payload, $preferred_keys = []) {
        if (!is_array($payload)) {
            return [];
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
