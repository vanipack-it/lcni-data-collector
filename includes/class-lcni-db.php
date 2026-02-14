<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_DB {

    const SYMBOL_BATCH_LIMIT = 50;

    public static function ensure_tables_exist() {
        global $wpdb;

        $required_tables = [
            $wpdb->prefix . 'lcni_ohlc',
            $wpdb->prefix . 'lcni_security_definition',
            $wpdb->prefix . 'lcni_symbols',
            $wpdb->prefix . 'lcni_change_logs',
            $wpdb->prefix . 'lcni_seed_tasks',
        ];

        foreach ($required_tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                self::create_tables();
                return;
            }
        }
    }

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $security_definition_table = $wpdb->prefix . 'lcni_security_definition';
        $symbol_table = $wpdb->prefix . 'lcni_symbols';
        $log_table = $wpdb->prefix . 'lcni_change_logs';
        $seed_task_table = $wpdb->prefix . 'lcni_seed_tasks';

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

        // Legacy table for backward compatibility.
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

        $sql_symbols = "CREATE TABLE {$symbol_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            symbol VARCHAR(20) NOT NULL,
            market_id VARCHAR(20) DEFAULT NULL,
            board_id VARCHAR(20) DEFAULT NULL,
            isin VARCHAR(30) DEFAULT NULL,
            product_grp_id VARCHAR(20) DEFAULT NULL,
            security_group_id VARCHAR(20) DEFAULT NULL,
            basic_price DECIMAL(20,6) DEFAULT NULL,
            ceiling_price DECIMAL(20,6) DEFAULT NULL,
            floor_price DECIMAL(20,6) DEFAULT NULL,
            open_interest_quantity BIGINT DEFAULT NULL,
            security_status VARCHAR(20) DEFAULT NULL,
            symbol_admin_status_code VARCHAR(20) DEFAULT NULL,
            symbol_trading_method_status_code VARCHAR(20) DEFAULT NULL,
            symbol_trading_sanction_status_code VARCHAR(20) DEFAULT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'manual',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_symbol (symbol),
            KEY idx_market_board (market_id, board_id),
            KEY idx_source (source)
        ) {$charset_collate};";

        $sql_seed_tasks = "CREATE TABLE {$seed_task_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            symbol VARCHAR(20) NOT NULL,
            timeframe VARCHAR(10) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            last_to_time BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_symbol_timeframe (symbol, timeframe),
            KEY idx_status (status),
            KEY idx_updated_at (updated_at)
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
        dbDelta($sql_symbols);
        dbDelta($sql_change_logs);
        dbDelta($sql_seed_tasks);

        self::log_change('activation', 'Created/updated OHLC, lcni_symbols, seed task and change log tables.');
    }

    public static function collect_all_data($latest_only = false, $offset = 0, $batch_limit = self::SYMBOL_BATCH_LIMIT) {
        self::ensure_tables_exist();

        $security_summary = self::collect_security_definitions();
        $ohlc_summary = self::collect_ohlc_data($latest_only, $offset, $batch_limit);

        return [
            'security' => $security_summary,
            'ohlc' => $ohlc_summary,
        ];
    }

    public static function collect_security_definitions() {
        self::ensure_tables_exist();

        $payload = LCNI_API::get_security_definitions();
        $cached_symbols = self::count_cached_security_symbols();

        if (!is_array($payload)) {
            $error_message = LCNI_API::get_last_request_error();
            $log_message = 'Unable to collect security definitions: invalid payload.';

            if ($error_message !== '') {
                $log_message .= ' ' . $error_message;
            }

            if ($cached_symbols > 0) {
                self::log_change('sync_security_cached', $log_message . sprintf(' Using %d cached symbols.', $cached_symbols));

                return [
                    'updated' => 0,
                    'cached_symbols' => $cached_symbols,
                    'used_cache' => true,
                ];
            }

            self::log_change('sync_failed', $log_message);

            return new WP_Error('invalid_payload', $error_message !== '' ? $error_message : 'Invalid security definitions payload.');
        }

        $rows = self::extract_items($payload, ['data', 'items', 'secDefs', 'secdefs', 'securities', 'symbols']);

        if (empty($rows)) {
            if ($cached_symbols > 0) {
                self::log_change('sync_security_cached', sprintf('No remote security definitions returned. Using %d cached symbols.', $cached_symbols));

                return [
                    'updated' => 0,
                    'cached_symbols' => $cached_symbols,
                    'used_cache' => true,
                ];
            }

            self::log_change('sync_skipped', 'No security definitions returned from DNSE API.');

            return new WP_Error('empty_payload', 'Security definitions payload is empty.');
        }

        $upsert_summary = self::upsert_symbol_rows($rows, 'api');

        self::log_change('sync_security_definition', sprintf('Upserted %d symbols from Security Definition.', (int) $upsert_summary['updated']));

        return [
            'updated' => (int) $upsert_summary['updated'],
            'total' => count($rows),
        ];
    }

    public static function import_symbols_from_csv($file_path) {
        self::ensure_tables_exist();

        if (!is_readable($file_path)) {
            return new WP_Error('csv_not_readable', 'Không thể đọc file CSV đã upload.');
        }

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return new WP_Error('csv_open_failed', 'Không thể mở file CSV.');
        }

        $header = fgetcsv($handle);
        $has_header = false;
        $header_map = [];

        if (is_array($header)) {
            foreach ($header as $idx => $value) {
                $normalized = self::normalize_csv_header($value);
                if ($normalized !== '') {
                    $header_map[$normalized] = $idx;
                }
            }

            $has_header = isset($header_map['symbol']) || isset($header_map['mck']);
        }

        $rows = [];

        if (!$has_header && is_array($header)) {
            $symbol = strtoupper(trim((string) ($header[0] ?? '')));
            if ($symbol !== '') {
                $rows[] = ['symbol' => $symbol];
            }
        }

        while (($line = fgetcsv($handle)) !== false) {
            if (!is_array($line) || empty($line)) {
                continue;
            }

            if ($has_header) {
                $symbol_index = $header_map['symbol'] ?? $header_map['mck'] ?? null;
                $symbol = $symbol_index !== null ? strtoupper(trim((string) ($line[$symbol_index] ?? ''))) : '';
                if ($symbol === '') {
                    continue;
                }

                $rows[] = [
                    'symbol' => $symbol,
                    'marketId' => self::csv_col($line, $header_map, ['marketid', 'market_id']),
                    'boardId' => self::csv_col($line, $header_map, ['boardid', 'board_id']),
                    'isin' => self::csv_col($line, $header_map, ['isin']),
                    'productGrpId' => self::csv_col($line, $header_map, ['productgrpid', 'product_grp_id']),
                    'securityGroupId' => self::csv_col($line, $header_map, ['securitygroupid', 'security_group_id']),
                    'basicPrice' => self::csv_col($line, $header_map, ['basicprice', 'basic_price']),
                    'ceilingPrice' => self::csv_col($line, $header_map, ['ceilingprice', 'ceiling_price']),
                    'floorPrice' => self::csv_col($line, $header_map, ['floorprice', 'floor_price']),
                    'openInterestQuantity' => self::csv_col($line, $header_map, ['openinterestquantity', 'open_interest_quantity']),
                    'securityStatus' => self::csv_col($line, $header_map, ['securitystatus', 'security_status']),
                    'symbolAdminStatusCode' => self::csv_col($line, $header_map, ['symboladminstatuscode', 'symbol_admin_status_code']),
                    'symbolTradingMethodStatusCode' => self::csv_col($line, $header_map, ['symboltradingmethodstatuscode', 'symbol_trading_method_status_code']),
                    'symbolTradingSanctionStatusCode' => self::csv_col($line, $header_map, ['symboltradingsanctionstatuscode', 'symbol_trading_sanction_status_code']),
                ];
            } else {
                $symbol = strtoupper(trim((string) ($line[0] ?? '')));
                if ($symbol !== '') {
                    $rows[] = ['symbol' => $symbol];
                }
            }
        }

        fclose($handle);

        if (empty($rows)) {
            return new WP_Error('empty_csv', 'File CSV không có symbol hợp lệ.');
        }

        $upsert_summary = self::upsert_symbol_rows($rows, 'csv');
        self::log_change('import_symbol_csv', sprintf('Imported %d symbols from CSV.', (int) $upsert_summary['updated']));

        return [
            'updated' => (int) $upsert_summary['updated'],
            'total' => count($rows),
        ];
    }

    public static function collect_ohlc_data($latest_only = false, $offset = 0, $batch_limit = self::SYMBOL_BATCH_LIMIT) {
        self::ensure_tables_exist();

        global $wpdb;

        $timeframe = strtoupper((string) get_option('lcni_timeframe', '1D'));
        $days = max(1, (int) get_option('lcni_days_to_load', 365));

        $batch_limit = max(1, (int) $batch_limit);
        $offset = max(0, (int) $offset);

        $symbol_table = $wpdb->prefix . 'lcni_symbols';
        $total_symbols = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$symbol_table}");

        $symbols = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT symbol FROM {$symbol_table} ORDER BY symbol ASC LIMIT %d OFFSET %d",
                $batch_limit,
                $offset
            )
        );

        if (empty($symbols)) {
            self::log_change('sync_skipped', 'No symbols available in lcni_symbols table for OHLC sync.');

            return [
                'inserted' => 0,
                'updated' => 0,
                'processed_symbols' => 0,
                'total_symbols' => $total_symbols,
                'next_offset' => $offset,
                'has_more' => false,
            ];
        }

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

            $upsert_summary = self::upsert_ohlc_rows($rows);
            $inserted += (int) $upsert_summary['inserted'];
            $updated += (int) $upsert_summary['updated'];
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

    public static function get_all_symbols() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_symbols';

        return $wpdb->get_col("SELECT symbol FROM {$table} ORDER BY symbol ASC");
    }

    public static function upsert_ohlc_rows($rows) {
        global $wpdb;

        if (empty($rows) || !is_array($rows)) {
            return [
                'inserted' => 0,
                'updated' => 0,
            ];
        }

        $table = $wpdb->prefix . 'lcni_ohlc';
        $inserted = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $event_time = isset($row['event_time']) ? (int) $row['event_time'] : strtotime((string) ($row['candle_time'] ?? ''));
            if ($event_time <= 0) {
                continue;
            }

            $record = [
                'symbol' => strtoupper((string) ($row['symbol'] ?? '')),
                'timeframe' => strtoupper((string) ($row['timeframe'] ?? '1D')),
                'event_time' => $event_time,
                'open_price' => (float) ($row['open'] ?? 0),
                'high_price' => (float) ($row['high'] ?? 0),
                'low_price' => (float) ($row['low'] ?? 0),
                'close_price' => (float) ($row['close'] ?? 0),
                'volume' => (int) ($row['volume'] ?? 0),
                'value_traded' => (float) (($row['close'] ?? 0) * ($row['volume'] ?? 0)),
            ];

            if ($record['symbol'] === '') {
                continue;
            }

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

        return [
            'inserted' => $inserted,
            'updated' => $updated,
        ];
    }

    private static function upsert_symbol_rows($rows, $source = 'manual') {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_symbols';
        $updated = 0;

        foreach ($rows as $row) {
            $symbol = strtoupper((string) self::pick($row, ['symbol', 's']));
            if ($symbol === '') {
                continue;
            }

            $record = [
                'symbol' => $symbol,
                'market_id' => self::nullable_text(self::pick($row, ['marketId', 'market_id'])),
                'board_id' => self::nullable_text(self::pick($row, ['boardId', 'board_id'])),
                'isin' => self::nullable_text(self::pick($row, ['isin', 'ISIN'])),
                'product_grp_id' => self::nullable_text(self::pick($row, ['productGrpId', 'product_grp_id'])),
                'security_group_id' => self::nullable_text(self::pick($row, ['securityGroupId', 'security_group_id'])),
                'basic_price' => self::nullable_float(self::pick($row, ['basicPrice', 'basic_price', 'referencePrice', 'reference_price'])),
                'ceiling_price' => self::nullable_float(self::pick($row, ['ceilingPrice', 'ceiling_price'])),
                'floor_price' => self::nullable_float(self::pick($row, ['floorPrice', 'floor_price'])),
                'open_interest_quantity' => self::nullable_int(self::pick($row, ['openInterestQuantity', 'open_interest_quantity'])),
                'security_status' => self::nullable_text(self::pick($row, ['securityStatus', 'security_status'])),
                'symbol_admin_status_code' => self::nullable_text(self::pick($row, ['symbolAdminStatusCode', 'symbol_admin_status_code'])),
                'symbol_trading_method_status_code' => self::nullable_text(self::pick($row, ['symbolTradingMethodStatusCode', 'symbol_trading_method_status_code'])),
                'symbol_trading_sanction_status_code' => self::nullable_text(self::pick($row, ['symbolTradingSanctionStatusCode', 'symbol_trading_sanction_status_code'])),
                'source' => sanitize_text_field($source),
            ];

            $result = $wpdb->replace(
                $table,
                $record,
                ['%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s']
            );

            if ($result !== false) {
                $updated++;
            }
        }

        return ['updated' => $updated];
    }

    private static function count_cached_security_symbols() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_symbols';

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
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

        $keys = array_merge($preferred_keys, ['data', 'items', 'rows', 'result', 'results', 'content', 'candles', 'secDefs', 'secdefs']);

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

    private static function normalize_csv_header($value) {
        $value = (string) $value;
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

        $normalized = strtolower(trim($value));
        $normalized = str_replace([' ', '-', '.', '/', '\\'], '_', $normalized);

        return preg_replace('/[^a-z0-9_]/', '', $normalized);
    }

    private static function csv_col($line, $header_map, $keys) {
        foreach ($keys as $key) {
            if (isset($header_map[$key])) {
                return trim((string) ($line[$header_map[$key]] ?? ''));
            }
        }

        return null;
    }

    private static function looks_like_security_definition($row) {
        if (!is_array($row)) {
            return false;
        }

        $keys = ['symbol', 's', 'isin', 'marketId', 'boardId', 'referencePrice', 'basicPrice'];
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
