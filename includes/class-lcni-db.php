<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_DB {

    const SYMBOL_BATCH_LIMIT = 50;
    const DEFAULT_MARKETS = [
        ['market_id' => '1', 'exchange' => 'UPCOM'],
        ['market_id' => '2', 'exchange' => 'HOSE'],
        ['market_id' => '3', 'exchange' => 'HNX'],
    ];

    const DEFAULT_ICB2 = [
        ['id_icb2' => 1, 'name_icb2' => 'Bán lẻ'],
        ['id_icb2' => 2, 'name_icb2' => 'Bảo hiểm'],
        ['id_icb2' => 3, 'name_icb2' => 'Bất động sản'],
        ['id_icb2' => 4, 'name_icb2' => 'Công nghệ Thông tin'],
        ['id_icb2' => 5, 'name_icb2' => 'Dầu khí'],
        ['id_icb2' => 6, 'name_icb2' => 'Dịch vụ tài chính'],
        ['id_icb2' => 7, 'name_icb2' => 'Du lịch và Giải trí'],
        ['id_icb2' => 8, 'name_icb2' => 'Điện, nước & xăng dầu khí đốt'],
        ['id_icb2' => 9, 'name_icb2' => 'Hàng & Dịch vụ Công nghiệp'],
        ['id_icb2' => 10, 'name_icb2' => 'Hàng cá nhân & Gia dụng'],
        ['id_icb2' => 11, 'name_icb2' => 'Hóa chất'],
        ['id_icb2' => 12, 'name_icb2' => 'Ngân hàng'],
        ['id_icb2' => 13, 'name_icb2' => 'Ô tô và phụ tùng'],
        ['id_icb2' => 14, 'name_icb2' => 'Tài nguyên Cơ bản'],
        ['id_icb2' => 15, 'name_icb2' => 'Thực phẩm và đồ uống'],
        ['id_icb2' => 16, 'name_icb2' => 'Truyền thông'],
        ['id_icb2' => 17, 'name_icb2' => 'Viễn thông'],
        ['id_icb2' => 18, 'name_icb2' => 'Xây dựng và Vật liệu'],
        ['id_icb2' => 19, 'name_icb2' => 'Y tế'],
    ];

    public static function ensure_tables_exist() {
        global $wpdb;

        $required_tables = [
            $wpdb->prefix . 'lcni_ohlc',
            $wpdb->prefix . 'lcni_security_definition',
            $wpdb->prefix . 'lcni_symbols',
            $wpdb->prefix . 'lcni_change_logs',
            $wpdb->prefix . 'lcni_seed_tasks',
            $wpdb->prefix . 'lcni_marketid',
            $wpdb->prefix . 'lcni_icb2',
            $wpdb->prefix . 'lcni_sym_icb_market',
        ];

        foreach ($required_tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                self::create_tables();
                return;
            }
        }

        self::ensure_ohlc_indicator_columns();
        self::normalize_ohlc_numeric_columns();
    }

    public static function run_pending_migrations() {
        self::normalize_legacy_ratio_columns();
        self::repair_ohlc_ratio_columns_over_normalized();
        self::backfill_ohlc_trading_index_and_xay_nen();
        self::backfill_ohlc_nen_type_metrics();
    }

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $security_definition_table = $wpdb->prefix . 'lcni_security_definition';
        $symbol_table = $wpdb->prefix . 'lcni_symbols';
        $log_table = $wpdb->prefix . 'lcni_change_logs';
        $seed_task_table = $wpdb->prefix . 'lcni_seed_tasks';
        $market_table = $wpdb->prefix . 'lcni_marketid';
        $icb2_table = $wpdb->prefix . 'lcni_icb2';
        $symbol_market_icb_table = $wpdb->prefix . 'lcni_sym_icb_market';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_ohlc = "CREATE TABLE {$ohlc_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            symbol VARCHAR(20) NOT NULL,
            timeframe VARCHAR(10) NOT NULL,
            event_time BIGINT UNSIGNED NOT NULL,
            open_price DECIMAL(20,2) NOT NULL,
            high_price DECIMAL(20,2) NOT NULL,
            low_price DECIMAL(20,2) NOT NULL,
            close_price DECIMAL(20,2) NOT NULL,
            volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
            value_traded DECIMAL(24,2) NOT NULL DEFAULT 0,
            pct_t_1 DECIMAL(12,6) DEFAULT NULL,
            pct_t_3 DECIMAL(12,6) DEFAULT NULL,
            pct_1w DECIMAL(12,6) DEFAULT NULL,
            pct_1m DECIMAL(12,6) DEFAULT NULL,
            pct_3m DECIMAL(12,6) DEFAULT NULL,
            pct_6m DECIMAL(12,6) DEFAULT NULL,
            pct_1y DECIMAL(12,6) DEFAULT NULL,
            ma10 DECIMAL(20,2) DEFAULT NULL,
            ma20 DECIMAL(20,2) DEFAULT NULL,
            ma50 DECIMAL(20,2) DEFAULT NULL,
            ma100 DECIMAL(20,2) DEFAULT NULL,
            ma200 DECIMAL(20,2) DEFAULT NULL,
            h1m DECIMAL(20,2) DEFAULT NULL,
            h3m DECIMAL(20,2) DEFAULT NULL,
            h6m DECIMAL(20,2) DEFAULT NULL,
            h1y DECIMAL(20,2) DEFAULT NULL,
            l1m DECIMAL(20,2) DEFAULT NULL,
            l3m DECIMAL(20,2) DEFAULT NULL,
            l6m DECIMAL(20,2) DEFAULT NULL,
            l1y DECIMAL(20,2) DEFAULT NULL,
            vol_ma10 DECIMAL(24,4) DEFAULT NULL,
            vol_ma20 DECIMAL(24,4) DEFAULT NULL,
            gia_sv_ma10 DECIMAL(12,6) DEFAULT NULL,
            gia_sv_ma20 DECIMAL(12,6) DEFAULT NULL,
            gia_sv_ma50 DECIMAL(12,6) DEFAULT NULL,
            gia_sv_ma100 DECIMAL(12,6) DEFAULT NULL,
            gia_sv_ma200 DECIMAL(12,6) DEFAULT NULL,
            vol_sv_vol_ma10 DECIMAL(12,6) DEFAULT NULL,
            vol_sv_vol_ma20 DECIMAL(12,6) DEFAULT NULL,
            macd DECIMAL(16,8) DEFAULT NULL,
            macd_signal DECIMAL(16,8) DEFAULT NULL,
            rsi DECIMAL(12,6) DEFAULT NULL,
            trading_index BIGINT UNSIGNED DEFAULT NULL,
            xay_nen VARCHAR(50) DEFAULT NULL,
            xay_nen_count_30 SMALLINT UNSIGNED DEFAULT NULL,
            nen_type VARCHAR(30) DEFAULT NULL,
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
            id_icb2 SMALLINT UNSIGNED DEFAULT NULL,
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
            KEY idx_id_icb2 (id_icb2),
            KEY idx_source (source)
        ) {$charset_collate};";

        $sql_symbol_market_icb = "CREATE TABLE {$symbol_market_icb_table} (
            symbol VARCHAR(20) NOT NULL,
            market_id VARCHAR(20) DEFAULT NULL,
            id_icb2 SMALLINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (symbol),
            KEY idx_market_id (market_id),
            KEY idx_id_icb2 (id_icb2)
        ) {$charset_collate};";

        $sql_seed_tasks = "CREATE TABLE {$seed_task_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            symbol VARCHAR(20) NOT NULL,
            timeframe VARCHAR(10) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            last_to_time BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_symbol_timeframe (symbol, timeframe),
            KEY idx_status (status),
            KEY idx_failed_attempts (failed_attempts),
            KEY idx_updated_at (updated_at)
        ) {$charset_collate};";

        $sql_market = "CREATE TABLE {$market_table} (
            market_id VARCHAR(20) NOT NULL,
            exchange VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (market_id)
        ) {$charset_collate};";

        $sql_icb2 = "CREATE TABLE {$icb2_table} (
            id_icb2 SMALLINT UNSIGNED NOT NULL,
            name_icb2 VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id_icb2),
            KEY idx_name_icb2 (name_icb2)
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
        dbDelta($sql_market);
        dbDelta($sql_icb2);
        dbDelta($sql_symbol_market_icb);

        self::seed_market_reference_data($market_table);
        self::seed_icb2_reference_data($icb2_table);
        self::sync_symbol_market_icb_mapping();
        self::ensure_ohlc_indicator_columns();
        self::normalize_ohlc_numeric_columns();
        self::normalize_legacy_ratio_columns();

        self::log_change('activation', 'Created/updated OHLC, lcni_symbols, seed task, market, icb2, sym_icb_market and change log tables.');
    }

    private static function ensure_ohlc_indicator_columns() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($columns) || empty($columns)) {
            return;
        }

        $required_columns = [
            'pct_t_1' => 'DECIMAL(12,6) DEFAULT NULL',
            'pct_t_3' => 'DECIMAL(12,6) DEFAULT NULL',
            'pct_1w' => 'DECIMAL(12,6) DEFAULT NULL',
            'pct_1m' => 'DECIMAL(12,6) DEFAULT NULL',
            'pct_3m' => 'DECIMAL(12,6) DEFAULT NULL',
            'pct_6m' => 'DECIMAL(12,6) DEFAULT NULL',
            'pct_1y' => 'DECIMAL(12,6) DEFAULT NULL',
            'ma10' => 'DECIMAL(20,2) DEFAULT NULL',
            'ma20' => 'DECIMAL(20,2) DEFAULT NULL',
            'ma50' => 'DECIMAL(20,2) DEFAULT NULL',
            'ma100' => 'DECIMAL(20,2) DEFAULT NULL',
            'ma200' => 'DECIMAL(20,2) DEFAULT NULL',
            'h1m' => 'DECIMAL(20,2) DEFAULT NULL',
            'h3m' => 'DECIMAL(20,2) DEFAULT NULL',
            'h6m' => 'DECIMAL(20,2) DEFAULT NULL',
            'h1y' => 'DECIMAL(20,2) DEFAULT NULL',
            'l1m' => 'DECIMAL(20,2) DEFAULT NULL',
            'l3m' => 'DECIMAL(20,2) DEFAULT NULL',
            'l6m' => 'DECIMAL(20,2) DEFAULT NULL',
            'l1y' => 'DECIMAL(20,2) DEFAULT NULL',
            'vol_ma10' => 'DECIMAL(24,4) DEFAULT NULL',
            'vol_ma20' => 'DECIMAL(24,4) DEFAULT NULL',
            'gia_sv_ma10' => 'DECIMAL(12,6) DEFAULT NULL',
            'gia_sv_ma20' => 'DECIMAL(12,6) DEFAULT NULL',
            'gia_sv_ma50' => 'DECIMAL(12,6) DEFAULT NULL',
            'gia_sv_ma100' => 'DECIMAL(12,6) DEFAULT NULL',
            'gia_sv_ma200' => 'DECIMAL(12,6) DEFAULT NULL',
            'vol_sv_vol_ma10' => 'DECIMAL(12,6) DEFAULT NULL',
            'vol_sv_vol_ma20' => 'DECIMAL(12,6) DEFAULT NULL',
            'macd' => 'DECIMAL(16,8) DEFAULT NULL',
            'macd_signal' => 'DECIMAL(16,8) DEFAULT NULL',
            'rsi' => 'DECIMAL(12,6) DEFAULT NULL',
            'trading_index' => 'BIGINT UNSIGNED DEFAULT NULL',
            'xay_nen' => 'VARCHAR(50) DEFAULT NULL',
            'xay_nen_count_30' => 'SMALLINT UNSIGNED DEFAULT NULL',
            'nen_type' => 'VARCHAR(30) DEFAULT NULL',
        ];

        foreach ($required_columns as $column_name => $column_definition) {
            if (in_array($column_name, $columns, true)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column_name} {$column_definition}");
        }
    }

    private static function normalize_ohlc_numeric_columns() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $columns = [
            'open_price' => 'DECIMAL(20,2) NOT NULL',
            'high_price' => 'DECIMAL(20,2) NOT NULL',
            'low_price' => 'DECIMAL(20,2) NOT NULL',
            'close_price' => 'DECIMAL(20,2) NOT NULL',
            'value_traded' => 'DECIMAL(24,2) NOT NULL DEFAULT 0',
            'ma10' => 'DECIMAL(20,2) NULL',
            'ma20' => 'DECIMAL(20,2) NULL',
            'ma50' => 'DECIMAL(20,2) NULL',
            'ma100' => 'DECIMAL(20,2) NULL',
            'ma200' => 'DECIMAL(20,2) NULL',
            'h1m' => 'DECIMAL(20,2) NULL',
            'h3m' => 'DECIMAL(20,2) NULL',
            'h6m' => 'DECIMAL(20,2) NULL',
            'h1y' => 'DECIMAL(20,2) NULL',
            'l1m' => 'DECIMAL(20,2) NULL',
            'l3m' => 'DECIMAL(20,2) NULL',
            'l6m' => 'DECIMAL(20,2) NULL',
            'l1y' => 'DECIMAL(20,2) NULL',
        ];

        foreach ($columns as $column_name => $column_definition) {
            $column = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column_name), ARRAY_A);
            if (!is_array($column)) {
                continue;
            }

            $is_matching_type = isset($column['Type']) && strtolower((string) $column['Type']) === strtolower(str_replace([' NOT NULL', ' NULL', ' DEFAULT 0'], '', $column_definition));
            if ($is_matching_type) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN {$column_name} {$column_definition}");
        }
    }

    private static function normalize_legacy_ratio_columns() {
        global $wpdb;

        $migration_flag = 'lcni_ohlc_ratio_columns_normalized_v1';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        $table = $wpdb->prefix . 'lcni_ohlc';
        $ratio_columns = [
            'pct_t_1', 'pct_t_3', 'pct_1w', 'pct_1m', 'pct_3m', 'pct_6m', 'pct_1y',
            'gia_sv_ma10', 'gia_sv_ma20', 'gia_sv_ma50', 'gia_sv_ma100', 'gia_sv_ma200',
            'vol_sv_vol_ma10', 'vol_sv_vol_ma20',
        ];

        $has_percent_scale_values = false;
        foreach ($ratio_columns as $column_name) {
            $max_abs_value = (float) $wpdb->get_var("SELECT MAX(ABS({$column_name})) FROM {$table}");
            if ($max_abs_value >= 10) {
                $has_percent_scale_values = true;
                break;
            }
        }

        if ($has_percent_scale_values) {
            $set_clauses = [];
            foreach ($ratio_columns as $column_name) {
                $set_clauses[] = "{$column_name} = CASE
                    WHEN {$column_name} IS NULL THEN NULL
                    WHEN ABS({$column_name}) >= 10 THEN {$column_name} / 100
                    ELSE {$column_name}
                END";
            }

            $wpdb->query("UPDATE {$table} SET " . implode(', ', $set_clauses));
            self::log_change('normalize_ohlc_ratio_columns', 'Normalized ratio indicator columns from percent scale to decimal scale (divide by 100).');
        }

        update_option($migration_flag, 'yes');
    }

    private static function repair_ohlc_ratio_columns_over_normalized() {
        global $wpdb;

        $migration_flag = 'lcni_ohlc_ratio_columns_repaired_v1';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        $table = $wpdb->prefix . 'lcni_ohlc';
        $all_series = $wpdb->get_results(
            "SELECT DISTINCT symbol, timeframe FROM {$table}",
            ARRAY_A
        );

        if (empty($all_series)) {
            update_option($migration_flag, 'yes');

            return;
        }

        foreach ($all_series as $series) {
            self::rebuild_ohlc_indicators($series['symbol'], $series['timeframe']);
            self::rebuild_ohlc_trading_index($series['symbol']);
        }

        self::log_change(
            'repair_ohlc_ratio_columns',
            sprintf('Rebuilt indicators for %d symbol/timeframe series to repair over-normalized ratio columns.', count($all_series))
        );
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_trading_index_and_xay_nen() {
        global $wpdb;

        $migration_flag = 'lcni_ohlc_trading_index_xay_nen_backfilled_v1';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        $table = $wpdb->prefix . 'lcni_ohlc';
        $series_with_missing_values = $wpdb->get_results(
            "SELECT DISTINCT symbol, timeframe
            FROM {$table}
            WHERE trading_index IS NULL
                OR xay_nen IS NULL",
            ARRAY_A
        );

        if (empty($series_with_missing_values)) {
            update_option($migration_flag, 'yes');

            return;
        }

        foreach ($series_with_missing_values as $series) {
            self::rebuild_ohlc_indicators($series['symbol'], $series['timeframe']);
            self::rebuild_ohlc_trading_index($series['symbol']);
        }

        self::log_change(
            'backfill_ohlc_trading_index_xay_nen',
            sprintf('Backfilled trading_index/xay_nen for %d symbol/timeframe series with missing values.', count($series_with_missing_values))
        );
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_nen_type_metrics() {
        global $wpdb;

        $migration_flag = 'lcni_ohlc_nen_type_metrics_backfilled_v1';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        $table = $wpdb->prefix . 'lcni_ohlc';
        $series_with_missing_values = $wpdb->get_results(
            "SELECT DISTINCT symbol, timeframe
            FROM {$table}
            WHERE xay_nen_count_30 IS NULL
                OR nen_type IS NULL",
            ARRAY_A
        );

        if (empty($series_with_missing_values)) {
            update_option($migration_flag, 'yes');

            return;
        }

        foreach ($series_with_missing_values as $series) {
            self::rebuild_ohlc_indicators($series['symbol'], $series['timeframe']);
        }

        self::log_change(
            'backfill_ohlc_nen_type_metrics',
            sprintf('Backfilled xay_nen_count_30/nen_type for %d symbol/timeframe series with missing values.', count($series_with_missing_values))
        );
        update_option($migration_flag, 'yes');
    }

    private static function seed_market_reference_data($market_table) {
        global $wpdb;

        foreach (self::DEFAULT_MARKETS as $market) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$market_table} (market_id, exchange)
                    VALUES (%s, %s)
                    ON DUPLICATE KEY UPDATE exchange = VALUES(exchange), updated_at = CURRENT_TIMESTAMP",
                    (string) $market['market_id'],
                    (string) $market['exchange']
                )
            );
        }
    }

    private static function seed_icb2_reference_data($icb2_table) {
        global $wpdb;

        foreach (self::DEFAULT_ICB2 as $icb2_row) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$icb2_table} (id_icb2, name_icb2)
                    VALUES (%d, %s)
                    ON DUPLICATE KEY UPDATE name_icb2 = VALUES(name_icb2), updated_at = CURRENT_TIMESTAMP",
                    (int) $icb2_row['id_icb2'],
                    (string) $icb2_row['name_icb2']
                )
            );
        }
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

        self::rebuild_missing_ohlc_indicators();

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
        $touched_series = [];

        foreach ($rows as $row) {
            $event_time = isset($row['event_time']) ? (int) $row['event_time'] : strtotime((string) ($row['candle_time'] ?? ''));
            if ($event_time <= 0) {
                continue;
            }

            $record = [
                'symbol' => strtoupper((string) ($row['symbol'] ?? '')),
                'timeframe' => strtoupper((string) ($row['timeframe'] ?? '1D')),
                'event_time' => $event_time,
                'open_price' => self::normalize_price($row['open'] ?? 0),
                'high_price' => self::normalize_price($row['high'] ?? 0),
                'low_price' => self::normalize_price($row['low'] ?? 0),
                'close_price' => self::normalize_price($row['close'] ?? 0),
                'volume' => (int) ($row['volume'] ?? 0),
                'value_traded' => self::normalize_price((($row['close'] ?? 0) * ($row['volume'] ?? 0))),
            ];

            if ($record['symbol'] === '') {
                continue;
            }

            $series_key = $record['symbol'] . '|' . $record['timeframe'];
            $touched_series[$series_key] = [
                'symbol' => $record['symbol'],
                'timeframe' => $record['timeframe'],
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

        foreach ($touched_series as $series) {
            self::rebuild_ohlc_indicators($series['symbol'], $series['timeframe']);
            self::rebuild_ohlc_trading_index($series['symbol']);
        }

        if (!empty($touched_series)) {
            self::rebuild_missing_ohlc_indicators(5);
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
        ];
    }

    public static function rebuild_missing_ohlc_indicators($limit = 20) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $limit = max(1, (int) $limit);

        $missing_series = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT latest.symbol, latest.timeframe
                FROM (
                    SELECT symbol, timeframe, MAX(event_time) AS max_event_time
                    FROM {$table}
                    GROUP BY symbol, timeframe
                ) grouped
                INNER JOIN {$table} latest
                    ON latest.symbol = grouped.symbol
                    AND latest.timeframe = grouped.timeframe
                    AND latest.event_time = grouped.max_event_time
                WHERE latest.ma10 IS NULL
                    OR latest.ma20 IS NULL
                    OR latest.ma50 IS NULL
                    OR latest.macd IS NULL
                    OR latest.rsi IS NULL
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if (empty($missing_series)) {
            return 0;
        }

        foreach ($missing_series as $series) {
            self::rebuild_ohlc_indicators($series['symbol'], $series['timeframe']);
            self::rebuild_ohlc_trading_index($series['symbol']);
        }

        return count($missing_series);
    }


    private static function rebuild_ohlc_indicators($symbol, $timeframe) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, close_price, high_price, low_price, volume FROM {$table} WHERE symbol = %s AND timeframe = %s ORDER BY event_time ASC",
                strtoupper((string) $symbol),
                strtoupper((string) $timeframe)
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return;
        }

        $closes = [];
        $highs = [];
        $lows = [];
        $volumes = [];

        $ema12 = null;
        $ema26 = null;
        $signal = null;
        $avg_gain = null;
        $avg_loss = null;

        $ema12_multiplier = 2 / (12 + 1);
        $ema26_multiplier = 2 / (26 + 1);
        $signal_multiplier = 2 / (9 + 1);
        $xay_nen_flags = [];

        for ($i = 0; $i < count($rows); $i++) {
            $close = (float) $rows[$i]['close_price'];
            $high = (float) $rows[$i]['high_price'];
            $low = (float) $rows[$i]['low_price'];
            $volume = (float) $rows[$i]['volume'];

            $closes[] = $close;
            $highs[] = $high;
            $lows[] = $low;
            $volumes[] = $volume;

            if ($ema12 === null) {
                $ema12 = $close;
                $ema26 = $close;
            } else {
                $ema12 = ($close - $ema12) * $ema12_multiplier + $ema12;
                $ema26 = ($close - $ema26) * $ema26_multiplier + $ema26;
            }

            $macd = $ema12 - $ema26;
            if ($signal === null) {
                $signal = $macd;
            } else {
                $signal = ($macd - $signal) * $signal_multiplier + $signal;
            }

            $rsi = null;
            if ($i > 0) {
                $change = $close - (float) $closes[$i - 1];
                $gain = max($change, 0);
                $loss = max(-$change, 0);

                if ($i <= 14) {
                    $avg_gain = ($avg_gain ?? 0) + $gain;
                    $avg_loss = ($avg_loss ?? 0) + $loss;

                    if ($i === 14) {
                        $avg_gain /= 14;
                        $avg_loss /= 14;
                    }
                } else {
                    $avg_gain = (($avg_gain * 13) + $gain) / 14;
                    $avg_loss = (($avg_loss * 13) + $loss) / 14;
                }

                if ($i >= 14) {
                    if ($avg_loss == 0.0) {
                        $rsi = 100.0;
                    } else {
                        $rs = $avg_gain / $avg_loss;
                        $rsi = 100 - (100 / (1 + $rs));
                    }
                }
            }

            $ma10 = self::window_average($closes, $i, 10);
            $ma20 = self::window_average($closes, $i, 20);
            $ma50 = self::window_average($closes, $i, 50);
            $ma100 = self::window_average($closes, $i, 100);
            $ma200 = self::window_average($closes, $i, 200);
            $vol_ma10 = self::window_average($volumes, $i, 10);
            $vol_ma20 = self::window_average($volumes, $i, 20);

            $xay_nen = self::is_xay_nen(
                $rsi,
                self::safe_ratio_pct($close, $ma10),
                self::safe_ratio_pct($close, $ma20),
                self::safe_ratio_pct($close, $ma50),
                self::safe_ratio_pct($volume, $vol_ma20),
                $volume,
                self::change_pct($closes, $i, 1),
                self::change_pct($closes, $i, 5),
                self::change_pct($closes, $i, 21),
                self::change_pct($closes, $i, 63)
            );

            $xay_nen_flags[] = $xay_nen === 'xây nền' ? 1 : 0;
            $window_start = max(0, count($xay_nen_flags) - 30);
            $xay_nen_count_30 = array_sum(array_slice($xay_nen_flags, $window_start));

            $wpdb->update(
                $table,
                [
                    'trading_index' => $i + 1,
                    'pct_t_1' => self::change_pct($closes, $i, 1),
                    'pct_t_3' => self::change_pct($closes, $i, 3),
                    'pct_1w' => self::change_pct($closes, $i, 5),
                    'pct_1m' => self::change_pct($closes, $i, 21),
                    'pct_3m' => self::change_pct($closes, $i, 63),
                    'pct_6m' => self::change_pct($closes, $i, 126),
                    'pct_1y' => self::change_pct($closes, $i, 252),
                    'ma10' => self::normalize_nullable_price($ma10),
                    'ma20' => self::normalize_nullable_price($ma20),
                    'ma50' => self::normalize_nullable_price($ma50),
                    'ma100' => self::normalize_nullable_price($ma100),
                    'ma200' => self::normalize_nullable_price($ma200),
                    'h1m' => self::normalize_nullable_price(self::window_max($highs, $i, 21)),
                    'h3m' => self::normalize_nullable_price(self::window_max($highs, $i, 63)),
                    'h6m' => self::normalize_nullable_price(self::window_max($highs, $i, 126)),
                    'h1y' => self::normalize_nullable_price(self::window_max($highs, $i, 252)),
                    'l1m' => self::normalize_nullable_price(self::window_min($lows, $i, 21)),
                    'l3m' => self::normalize_nullable_price(self::window_min($lows, $i, 63)),
                    'l6m' => self::normalize_nullable_price(self::window_min($lows, $i, 126)),
                    'l1y' => self::normalize_nullable_price(self::window_min($lows, $i, 252)),
                    'vol_ma10' => $vol_ma10,
                    'vol_ma20' => $vol_ma20,
                    'gia_sv_ma10' => self::safe_ratio_pct($close, $ma10),
                    'gia_sv_ma20' => self::safe_ratio_pct($close, $ma20),
                    'gia_sv_ma50' => self::safe_ratio_pct($close, $ma50),
                    'gia_sv_ma100' => self::safe_ratio_pct($close, $ma100),
                    'gia_sv_ma200' => self::safe_ratio_pct($close, $ma200),
                    'vol_sv_vol_ma10' => self::safe_ratio_pct($volume, $vol_ma10),
                    'vol_sv_vol_ma20' => self::safe_ratio_pct($volume, $vol_ma20),
                    'macd' => $macd,
                    'macd_signal' => $signal,
                    'rsi' => $rsi,
                    'xay_nen' => $xay_nen,
                    'xay_nen_count_30' => $xay_nen_count_30,
                    'nen_type' => self::determine_nen_type($xay_nen_count_30),
                ],
                ['id' => (int) $rows[$i]['id']],
                ['%d','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%s','%d','%s'],
                ['%d']
            );
        }
    }

    private static function rebuild_ohlc_trading_index($symbol) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $symbol = strtoupper((string) $symbol);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE symbol = %s ORDER BY event_time ASC, id ASC",
                $symbol
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            $wpdb->update(
                $table,
                ['trading_index' => $index + 1],
                ['id' => (int) $row['id']],
                ['%d'],
                ['%d']
            );
        }
    }

    private static function is_xay_nen($rsi, $gia_sv_ma10, $gia_sv_ma20, $gia_sv_ma50, $vol_sv_vol_ma20, $volume, $pct_t_1, $pct_1w, $pct_1m, $pct_3m) {
        if ($rsi === null || $gia_sv_ma10 === null || $gia_sv_ma20 === null || $gia_sv_ma50 === null || $vol_sv_vol_ma20 === null || $pct_t_1 === null || $pct_1w === null || $pct_1m === null || $pct_3m === null) {
            return 'chưa đủ dữ liệu';
        }

        if (
            $rsi >= 38.5 && $rsi <= 75.8
            && abs((float) $gia_sv_ma10) <= 0.05
            && abs((float) $gia_sv_ma20) <= 0.07
            && abs((float) $gia_sv_ma50) <= 0.1
            && (float) $vol_sv_vol_ma20 <= 0.1
            && (float) $volume >= 100000
            && (float) $pct_t_1 >= -0.03 && (float) $pct_t_1 <= 0.03
            && (float) $pct_1w >= -0.05 && (float) $pct_1w <= 0.05
            && (float) $pct_1m >= -0.1 && (float) $pct_1m <= 0.1
            && (float) $pct_3m >= -0.15 && (float) $pct_3m <= 0.15
        ) {
            return 'xây nền';
        }

        return 'không xây nền';
    }

    private static function determine_nen_type($xay_nen_count_30) {
        if ($xay_nen_count_30 >= 24) {
            return 'Nền chặt';
        }

        if ($xay_nen_count_30 >= 15) {
            return 'Nền vừa';
        }

        return 'Nền lỏng';
    }

    private static function change_pct($series, $index, $lookback) {
        if ($index - $lookback < 0) {
            return null;
        }

        $base = (float) $series[$index - $lookback];
        if ($base == 0.0) {
            return null;
        }

        return ((float) $series[$index] / $base) - 1;
    }

    private static function normalize_price($value) {
        return round((float) $value, 2);
    }

    private static function normalize_nullable_price($value) {
        if ($value === null) {
            return null;
        }

        return self::normalize_price($value);
    }

    private static function window_average($series, $index, $window) {
        if ($index + 1 < $window) {
            return null;
        }

        $slice = array_slice($series, $index - $window + 1, $window);

        return array_sum($slice) / $window;
    }

    private static function window_max($series, $index, $window) {
        if ($index + 1 < $window) {
            return null;
        }

        return max(array_slice($series, $index - $window + 1, $window));
    }

    private static function window_min($series, $index, $window) {
        if ($index + 1 < $window) {
            return null;
        }

        return min(array_slice($series, $index - $window + 1, $window));
    }

    private static function safe_ratio_pct($value, $base) {
        if ($base === null || (float) $base == 0.0) {
            return null;
        }

        return ((float) $value / (float) $base) - 1;
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
                'id_icb2' => self::nullable_int(self::pick($row, ['idIcb2', 'id_icb2', 'icb2', 'industryId'])),
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
                ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s']
            );

            if ($result !== false) {
                $updated++;
            }
        }

        self::sync_symbol_market_icb_mapping();

        return ['updated' => $updated];
    }

    private static function sync_symbol_market_icb_mapping() {
        global $wpdb;

        $symbols_table = $wpdb->prefix . 'lcni_symbols';
        $mapping_table = $wpdb->prefix . 'lcni_sym_icb_market';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mapping_table)) !== $mapping_table) {
            return;
        }

        $wpdb->query(
            "INSERT INTO {$mapping_table} (symbol, market_id, id_icb2)
            SELECT symbol, market_id, id_icb2 FROM {$symbols_table}
            ON DUPLICATE KEY UPDATE
                market_id = VALUES(market_id),
                id_icb2 = VALUES(id_icb2),
                updated_at = CURRENT_TIMESTAMP"
        );
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
