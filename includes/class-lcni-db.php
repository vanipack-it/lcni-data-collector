<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_DB {

    private static $rule_settings_cache = null;
    private static $symbol_exchange_cache = [];

    const SYMBOL_BATCH_LIMIT = 50;
    const RULE_REBUILD_TASKS_OPTION = 'lcni_rule_rebuild_tasks';
    const RULE_REBUILD_STATUS_OPTION = 'lcni_rule_rebuild_status';
    const RULE_REBUILD_BATCH_SIZE = 5;
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
            $wpdb->prefix . 'lcni_symbol_tongquan',
            $wpdb->prefix . 'lcni_change_logs',
            $wpdb->prefix . 'lcni_seed_tasks',
            $wpdb->prefix . 'lcni_marketid',
            $wpdb->prefix . 'lcni_icb2',
            $wpdb->prefix . 'lcni_sym_icb_market',
            $wpdb->prefix . 'lcni_watchlist',
            $wpdb->prefix . 'lcni_saved_filters',
            $wpdb->prefix . 'lcni_watchlists',
            $wpdb->prefix . 'lcni_watchlist_symbols',
        ];

        foreach ($required_tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                self::create_tables();
                return;
            }
        }

        self::ensure_ohlc_indicator_columns();
        self::ensure_symbol_market_icb_columns();
        self::ensure_symbol_tongquan_columns();
        self::ensure_ohlc_indexes();
        self::ensure_ohlc_latest_snapshot_infrastructure();
        self::normalize_ohlc_numeric_columns();
        self::sync_symbol_market_icb_mapping();
        self::sync_symbol_tongquan_with_symbols();
    }

    public static function run_pending_migrations() {
        self::normalize_legacy_ratio_columns();
        self::repair_ohlc_ratio_columns_over_normalized();
        self::backfill_ohlc_trading_index_and_xay_nen();
        self::backfill_ohlc_nen_type_metrics();
        self::backfill_ohlc_pha_nen_metrics();
        self::backfill_ohlc_tang_gia_kem_vol_metrics();
        self::backfill_ohlc_smart_money_metrics();
        self::backfill_ohlc_rs_1m_by_exchange();
        self::backfill_ohlc_rs_1w_by_exchange();
        self::backfill_ohlc_rs_3m_by_exchange();
        self::backfill_ohlc_rs_exchange_signals();
        self::backfill_ohlc_rs_recommend_status();
        self::ensure_ohlc_indexes();
        self::ensure_ohlc_latest_snapshot_infrastructure();
    }

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $security_definition_table = $wpdb->prefix . 'lcni_security_definition';
        $symbol_table = $wpdb->prefix . 'lcni_symbols';
        $symbol_tongquan_table = $wpdb->prefix . 'lcni_symbol_tongquan';
        $log_table = $wpdb->prefix . 'lcni_change_logs';
        $seed_task_table = $wpdb->prefix . 'lcni_seed_tasks';
        $market_table = $wpdb->prefix . 'lcni_marketid';
        $icb2_table = $wpdb->prefix . 'lcni_icb2';
        $symbol_market_icb_table = $wpdb->prefix . 'lcni_sym_icb_market';
        $watchlist_table = $wpdb->prefix . 'lcni_watchlist';
        $saved_filters_table = $wpdb->prefix . 'lcni_saved_filters';
        $watchlists_table = $wpdb->prefix . 'lcni_watchlists';
        $watchlist_symbols_table = $wpdb->prefix . 'lcni_watchlist_symbols';

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
            rs_1m_by_exchange DECIMAL(6,2) DEFAULT NULL,
            rs_1w_by_exchange DECIMAL(6,2) DEFAULT NULL,
            rs_3m_by_exchange DECIMAL(6,2) DEFAULT NULL,
            rs_exchange_status VARCHAR(50) DEFAULT NULL,
            rs_exchange_recommend VARCHAR(50) DEFAULT NULL,
            rs_recommend_status VARCHAR(80) DEFAULT NULL,
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
            pha_nen VARCHAR(30) DEFAULT NULL,
            tang_gia_kem_vol VARCHAR(50) DEFAULT NULL,
            smart_money VARCHAR(30) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_ohlc (symbol, timeframe, event_time),
            KEY idx_symbol_timeframe (symbol, timeframe),
            KEY idx_event_time (event_time),
            KEY idx_symbol_index (symbol, trading_index)
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
            exchange VARCHAR(20) DEFAULT NULL,
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

        $sql_symbol_tongquan = "CREATE TABLE {$symbol_tongquan_table} (
            symbol VARCHAR(20) NOT NULL,
            eps DECIMAL(20,4) DEFAULT NULL,
            eps_1y_pct DECIMAL(12,6) DEFAULT NULL,
            dt_1y_pct DECIMAL(12,6) DEFAULT NULL,
            bien_ln_gop DECIMAL(12,6) DEFAULT NULL,
            bien_ln_rong DECIMAL(12,6) DEFAULT NULL,
            roe DECIMAL(12,6) DEFAULT NULL,
            de_ratio DECIMAL(12,6) DEFAULT NULL,
            pe_ratio DECIMAL(12,6) DEFAULT NULL,
            pb_ratio DECIMAL(12,6) DEFAULT NULL,
            ev_ebitda DECIMAL(12,6) DEFAULT NULL,
            tcbs_khuyen_nghi VARCHAR(255) DEFAULT NULL,
            co_tuc_pct DECIMAL(12,6) DEFAULT NULL,
            tc_rating DECIMAL(12,6) DEFAULT NULL,
            xep_hang VARCHAR(30) DEFAULT 'Chưa xếp hạng',
            so_huu_nn_pct DECIMAL(12,6) DEFAULT NULL,
            tien_mat_rong_von_hoa DECIMAL(12,6) DEFAULT NULL,
            tien_mat_rong_tong_tai_san DECIMAL(12,6) DEFAULT NULL,
            loi_nhuan_4_quy_gan_nhat DECIMAL(20,4) DEFAULT NULL,
            tang_truong_dt_quy_gan_nhat DECIMAL(12,6) DEFAULT NULL,
            tang_truong_dt_quy_gan_nhi DECIMAL(12,6) DEFAULT NULL,
            tang_truong_ln_quy_gan_nhat DECIMAL(12,6) DEFAULT NULL,
            tang_truong_ln_quy_gan_nhi DECIMAL(12,6) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (symbol)
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



        $sql_saved_filters = "CREATE TABLE {$saved_filters_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            filter_name VARCHAR(191) NOT NULL,
            filter_config LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user_id (user_id),
            KEY idx_user_name (user_id, filter_name)
        ) {$charset_collate};";

        $sql_watchlists = "CREATE TABLE {$watchlists_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user_id (user_id),
            KEY idx_user_default (user_id, is_default)
        ) {$charset_collate};";

        $sql_watchlist_symbols = "CREATE TABLE {$watchlist_symbols_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            watchlist_id BIGINT UNSIGNED NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_watchlist_symbol (watchlist_id, symbol),
            KEY idx_watchlist_id (watchlist_id),
            KEY idx_symbol (symbol)
        ) {$charset_collate};";

        $sql_watchlist = "CREATE TABLE {$watchlist_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_user_symbol (user_id, symbol),
            KEY idx_user_id (user_id),
            KEY idx_symbol (symbol)
        ) {$charset_collate};";

        dbDelta($sql_ohlc);
        dbDelta($sql_security_definition);
        dbDelta($sql_symbols);
        dbDelta($sql_change_logs);
        dbDelta($sql_seed_tasks);
        dbDelta($sql_market);
        dbDelta($sql_icb2);
        dbDelta($sql_symbol_market_icb);
        dbDelta($sql_symbol_tongquan);
        dbDelta($sql_watchlist);
        dbDelta($sql_saved_filters);
        dbDelta($sql_watchlists);
        dbDelta($sql_watchlist_symbols);

        self::seed_market_reference_data($market_table);
        self::seed_icb2_reference_data($icb2_table);
        self::sync_symbol_market_icb_mapping();
        self::ensure_ohlc_indicator_columns();
        self::ensure_symbol_market_icb_columns();
        self::ensure_symbol_tongquan_columns();
        self::ensure_ohlc_indexes();
        self::ensure_ohlc_latest_snapshot_infrastructure();
        self::normalize_ohlc_numeric_columns();
        self::normalize_legacy_ratio_columns();
        self::sync_symbol_tongquan_with_symbols();

        self::log_change('activation', 'Created/updated OHLC, lcni_symbols, lcni_symbol_tongquan, seed task, market, icb2, sym_icb_market and change log tables.');
    }

    public static function ensure_ohlc_latest_snapshot_infrastructure($enabled = null, $interval_minutes = null) {
        global $wpdb;

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $latest_table = $wpdb->prefix . 'lcni_ohlc_latest';
        $event_name = $wpdb->prefix . 'ev_sync_ohlc_latest';
        $sync_proc_name = $wpdb->prefix . 'sync_ohlc_latest_structure';
        $refresh_proc_name = $wpdb->prefix . 'refresh_ohlc_latest';

        $ohlc_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ohlc_table));
        if ($ohlc_exists !== $ohlc_table) {
            return;
        }

        $latest_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $latest_table));
        if ($latest_exists !== $latest_table) {
            $wpdb->query("CREATE TABLE {$latest_table} LIKE {$ohlc_table}");
        }

        $id_column = $wpdb->get_row("SHOW COLUMNS FROM {$latest_table} LIKE 'id'", ARRAY_A);
        if (is_array($id_column) && isset($id_column['Extra']) && strpos((string) $id_column['Extra'], 'auto_increment') !== false) {
            $wpdb->query("ALTER TABLE {$latest_table} MODIFY COLUMN id BIGINT UNSIGNED DEFAULT NULL");
        }

        $primary_key = $wpdb->get_row($wpdb->prepare("SHOW INDEX FROM {$latest_table} WHERE Key_name = %s", 'PRIMARY'), ARRAY_A);
        if (is_array($primary_key) && isset($primary_key['Column_name']) && $primary_key['Column_name'] !== 'symbol') {
            $wpdb->query("ALTER TABLE {$latest_table} DROP PRIMARY KEY");
        }

        $symbol_primary_key = $wpdb->get_row($wpdb->prepare("SHOW INDEX FROM {$latest_table} WHERE Key_name = %s", 'PRIMARY'), ARRAY_A);
        if (!is_array($symbol_primary_key)) {
            $wpdb->query("ALTER TABLE {$latest_table} ADD PRIMARY KEY (symbol)");
        }

        $wpdb->query("DROP PROCEDURE IF EXISTS {$sync_proc_name}");
        $wpdb->query(
            "CREATE PROCEDURE {$sync_proc_name}()
            BEGIN
                DECLARE done INT DEFAULT FALSE;
                DECLARE col_name VARCHAR(100);
                DECLARE col_type TEXT;

                DECLARE cur CURSOR FOR
                    SELECT column_name, column_type
                    FROM information_schema.columns
                    WHERE table_name = '{$wpdb->prefix}lcni_ohlc'
                        AND table_schema = DATABASE()
                        AND column_name NOT IN (
                            SELECT column_name
                            FROM information_schema.columns
                            WHERE table_name = '{$wpdb->prefix}lcni_ohlc_latest'
                                AND table_schema = DATABASE()
                        );

                DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

                OPEN cur;
                read_loop: LOOP
                    FETCH cur INTO col_name, col_type;
                    IF done THEN
                        LEAVE read_loop;
                    END IF;

                    SET @sql = CONCAT('ALTER TABLE {$latest_table} ADD COLUMN `', REPLACE(col_name, '`', '``'), '` ', col_type);
                    PREPARE stmt FROM @sql;
                    EXECUTE stmt;
                    DEALLOCATE PREPARE stmt;
                END LOOP;
                CLOSE cur;
            END"
        );

        $wpdb->query("DROP PROCEDURE IF EXISTS {$refresh_proc_name}");
        $wpdb->query(
            "CREATE PROCEDURE {$refresh_proc_name}()
            BEGIN
                REPLACE INTO {$latest_table}
                SELECT t.*
                FROM {$ohlc_table} t
                JOIN (
                    SELECT symbol, MAX(event_time) AS max_time
                    FROM {$ohlc_table}
                    GROUP BY symbol
                ) m ON t.symbol = m.symbol AND t.event_time = m.max_time;
            END"
        );

        $should_enable = is_null($enabled) ? !empty(get_option('lcni_ohlc_latest_enabled', false)) : (bool) $enabled;
        $interval = is_null($interval_minutes) ? (int) get_option('lcni_ohlc_latest_interval_minutes', 5) : (int) $interval_minutes;
        $interval = max(1, $interval);

        $wpdb->query("DROP EVENT IF EXISTS {$event_name}");
        if ($should_enable) {
            $wpdb->query('SET GLOBAL event_scheduler = ON');
            $wpdb->query(
                "CREATE EVENT {$event_name}
                ON SCHEDULE EVERY {$interval} MINUTE
                DO
                BEGIN
                    CALL {$sync_proc_name}();
                    CALL {$refresh_proc_name}();
                END"
            );
        }
    }


    public static function get_mysql_event_scheduler_status() {
        global $wpdb;

        $row = $wpdb->get_row("SHOW VARIABLES LIKE 'event_scheduler'", ARRAY_A);
        if (!is_array($row)) {
            return [
                'available' => false,
                'value' => 'unknown',
                'enabled' => false,
            ];
        }

        $value = isset($row['Value']) ? strtolower((string) $row['Value']) : 'unknown';

        return [
            'available' => true,
            'value' => $value,
            'enabled' => in_array($value, ['on', '1'], true),
        ];
    }

    public static function refresh_ohlc_latest_snapshot() {
        global $wpdb;

        self::ensure_ohlc_latest_snapshot_infrastructure();

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $latest_table = $wpdb->prefix . 'lcni_ohlc_latest';
        $missing_columns = $wpdb->get_results(
            "SELECT column_name, column_type
            FROM information_schema.columns
            WHERE table_name = '{$wpdb->prefix}lcni_ohlc'
                AND table_schema = DATABASE()
                AND column_name NOT IN (
                    SELECT column_name
                    FROM information_schema.columns
                    WHERE table_name = '{$wpdb->prefix}lcni_ohlc_latest'
                        AND table_schema = DATABASE()
                )",
            ARRAY_A
        );

        foreach ((array) $missing_columns as $column) {
            $column_name = isset($column['column_name']) ? str_replace('`', '``', (string) $column['column_name']) : '';
            $column_type = isset($column['column_type']) ? (string) $column['column_type'] : '';
            if ($column_name === '' || $column_type === '') {
                continue;
            }

            $wpdb->query("ALTER TABLE {$latest_table} ADD COLUMN `{$column_name}` {$column_type}");
        }

        $result = $wpdb->query(
            "REPLACE INTO {$latest_table}
            SELECT t.*
            FROM {$ohlc_table} t
            JOIN (
                SELECT symbol, MAX(event_time) AS max_time
                FROM {$ohlc_table}
                GROUP BY symbol
            ) m ON t.symbol = m.symbol AND t.event_time = m.max_time"
        );

        return [
            'success' => $result !== false,
            'rows_affected' => (int) $result,
            'error' => $result === false ? $wpdb->last_error : '',
        ];
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
            'rs_1m_by_exchange' => 'DECIMAL(6,2) DEFAULT NULL',
            'rs_1w_by_exchange' => 'DECIMAL(6,2) DEFAULT NULL',
            'rs_3m_by_exchange' => 'DECIMAL(6,2) DEFAULT NULL',
            'rs_exchange_status' => 'VARCHAR(50) DEFAULT NULL',
            'rs_exchange_recommend' => 'VARCHAR(50) DEFAULT NULL',
            'rs_recommend_status' => 'VARCHAR(80) DEFAULT NULL',
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
            'pha_nen' => 'VARCHAR(30) DEFAULT NULL',
            'tang_gia_kem_vol' => 'VARCHAR(50) DEFAULT NULL',
            'smart_money' => 'VARCHAR(30) DEFAULT NULL',
        ];

        foreach ($required_columns as $column_name => $column_definition) {
            if (in_array($column_name, $columns, true)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column_name} {$column_definition}");
        }
    }

    private static function ensure_symbol_market_icb_columns() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_sym_icb_market';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($columns) || empty($columns)) {
            return;
        }

        if (!in_array('exchange', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN exchange VARCHAR(20) DEFAULT NULL AFTER id_icb2");
        }
    }

    private static function ensure_symbol_tongquan_columns() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_symbol_tongquan';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($columns) || empty($columns)) {
            return;
        }

        $required_columns = [
            'eps' => 'DECIMAL(20,4) DEFAULT NULL',
            'eps_1y_pct' => 'DECIMAL(12,6) DEFAULT NULL',
            'dt_1y_pct' => 'DECIMAL(12,6) DEFAULT NULL',
            'bien_ln_gop' => 'DECIMAL(12,6) DEFAULT NULL',
            'bien_ln_rong' => 'DECIMAL(12,6) DEFAULT NULL',
            'roe' => 'DECIMAL(12,6) DEFAULT NULL',
            'de_ratio' => 'DECIMAL(12,6) DEFAULT NULL',
            'pe_ratio' => 'DECIMAL(12,6) DEFAULT NULL',
            'pb_ratio' => 'DECIMAL(12,6) DEFAULT NULL',
            'ev_ebitda' => 'DECIMAL(12,6) DEFAULT NULL',
            'tcbs_khuyen_nghi' => 'VARCHAR(255) DEFAULT NULL',
            'co_tuc_pct' => 'DECIMAL(12,6) DEFAULT NULL',
            'tc_rating' => 'DECIMAL(12,6) DEFAULT NULL',
            'xep_hang' => "VARCHAR(30) DEFAULT 'Chưa xếp hạng'",
            'so_huu_nn_pct' => 'DECIMAL(12,6) DEFAULT NULL',
            'tien_mat_rong_von_hoa' => 'DECIMAL(12,6) DEFAULT NULL',
            'tien_mat_rong_tong_tai_san' => 'DECIMAL(12,6) DEFAULT NULL',
            'loi_nhuan_4_quy_gan_nhat' => 'DECIMAL(20,4) DEFAULT NULL',
            'tang_truong_dt_quy_gan_nhat' => 'DECIMAL(12,6) DEFAULT NULL',
            'tang_truong_dt_quy_gan_nhi' => 'DECIMAL(12,6) DEFAULT NULL',
            'tang_truong_ln_quy_gan_nhat' => 'DECIMAL(12,6) DEFAULT NULL',
            'tang_truong_ln_quy_gan_nhi' => 'DECIMAL(12,6) DEFAULT NULL',
        ];

        foreach ($required_columns as $column_name => $column_definition) {
            if (in_array($column_name, $columns, true)) {
                continue;
            }

            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column_name} {$column_definition}");
        }

        self::refresh_symbol_tongquan_rankings();
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

    private static function ensure_ohlc_indexes() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $index_name = 'idx_symbol_index';
        $index_exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index_name));

        if ($index_exists === null) {
            $wpdb->query("CREATE INDEX {$index_name} ON {$table} (symbol, trading_index)");
        }
    }

    public static function get_default_rule_settings() {
        return [
            'xay_nen_rsi_min' => 38.5,
            'xay_nen_rsi_max' => 75.8,
            'xay_nen_gia_sv_ma10_abs_max' => 0.05,
            'xay_nen_gia_sv_ma20_abs_max' => 0.07,
            'xay_nen_gia_sv_ma50_abs_max' => 0.1,
            'xay_nen_vol_sv_vol_ma20_max' => 0.1,
            'xay_nen_volume_min' => 100000,
            'xay_nen_pct_t_1_abs_max' => 0.03,
            'xay_nen_pct_1w_abs_max' => 0.05,
            'xay_nen_pct_1m_abs_max' => 0.1,
            'xay_nen_pct_3m_abs_max' => 0.15,
            'nen_type_chat_min_count_30' => 24,
            'nen_type_vua_min_count_30' => 15,
            'pha_nen_pct_t_1_min' => 0.03,
            'pha_nen_vol_sv_vol_ma20_min' => 0.5,
            'tang_gia_kem_vol_hose_pct_t_1_min' => 0.03,
            'tang_gia_kem_vol_hnx_pct_t_1_min' => 0.06,
            'tang_gia_kem_vol_upcom_pct_t_1_min' => 0.10,
            'tang_gia_kem_vol_vol_ratio_ma10_min' => 1,
            'tang_gia_kem_vol_vol_ratio_ma20_min' => 1.5,
            'rs_exchange_status_song_manh_1w_min' => 80,
            'rs_exchange_status_song_manh_1m_min' => 70,
            'rs_exchange_status_song_manh_3m_max' => 50,
            'rs_exchange_status_giu_trend_1w_min' => 60,
            'rs_exchange_status_giu_trend_1m_min' => 70,
            'rs_exchange_status_giu_trend_3m_min' => 70,
            'rs_exchange_status_yeu_1w_max' => 50,
            'rs_exchange_status_yeu_1m_max' => 50,
            'rs_exchange_status_yeu_3m_max' => 50,
            'rs_exchange_recommend_volume_min' => 50000,
            'rs_exchange_recommend_buy_1w_min' => 70,
            'rs_exchange_recommend_buy_1w_gain_over_1m' => 0,
            'rs_exchange_recommend_buy_pct_1w_min' => 0.03,
            'rs_exchange_recommend_buy_pct_1m_max' => 0.2,
            'rs_exchange_recommend_buy_pct_3m_max' => 0.4,
            'rs_exchange_recommend_buy_pct_t_1_min' => 0.02,
            'rs_exchange_recommend_buy_volume_boost_ratio' => 1.5,
            'rs_exchange_recommend_sell_1w_max' => 50,
            'rs_exchange_recommend_sell_pct_1w_max' => -0.03,
        ];
    }

    public static function get_rule_settings() {
        if (is_array(self::$rule_settings_cache)) {
            return self::$rule_settings_cache;
        }

        $stored = get_option('lcni_rule_settings', []);
        self::$rule_settings_cache = self::sanitize_rule_settings($stored);

        return self::$rule_settings_cache;
    }

    public static function sanitize_rule_settings($raw_settings) {
        $defaults = self::get_default_rule_settings();
        $raw = is_array($raw_settings) ? $raw_settings : [];
        $settings = [];

        foreach ($defaults as $key => $default_value) {
            $candidate = array_key_exists($key, $raw) ? $raw[$key] : $default_value;
            $normalized_value = self::normalize_rule_setting_numeric_value($candidate);

            if ($normalized_value === null || !is_numeric($normalized_value)) {
                $normalized_value = $default_value;
            }

            if (is_int($default_value)) {
                $settings[$key] = max(0, (int) $normalized_value);
            } else {
                $settings[$key] = (float) $normalized_value;
            }
        }

        if ($settings['nen_type_vua_min_count_30'] > $settings['nen_type_chat_min_count_30']) {
            $settings['nen_type_vua_min_count_30'] = $settings['nen_type_chat_min_count_30'];
        }

        return $settings;
    }

    public static function validate_rule_settings_input($raw_settings) {
        $defaults = self::get_default_rule_settings();
        $raw = is_array($raw_settings) ? $raw_settings : [];
        $normalized = [];
        $errors = [];

        foreach ($raw as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                continue;
            }

            $normalized_value = self::normalize_rule_setting_numeric_value($value);
            if ($normalized_value === null || !is_numeric($normalized_value)) {
                $errors[] = $key;
                continue;
            }

            if (is_int($defaults[$key])) {
                $normalized[$key] = max(0, (int) $normalized_value);
            } else {
                $normalized[$key] = (float) $normalized_value;
            }
        }

        if (!empty($errors)) {
            return new WP_Error(
                'invalid_rule_settings',
                sprintf('Giá trị Rule Setting không hợp lệ ở các trường: %s. Vui lòng nhập số theo định dạng 0.05 hoặc 0,05.', implode(', ', $errors))
            );
        }

        return $normalized;
    }

    private static function normalize_rule_setting_numeric_value($value) {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace([' ', '%'], '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? $normalized : null;
    }

    public static function update_rule_settings($raw_settings, $force_recalculate = false) {
        $current = self::get_rule_settings();
        $incoming = is_array($raw_settings) ? $raw_settings : [];
        $sanitized = self::sanitize_rule_settings(array_merge($current, $incoming));

        update_option('lcni_rule_settings', $sanitized);
        self::$rule_settings_cache = $sanitized;

        if ($current === $sanitized && !$force_recalculate) {
            return ['updated' => false, 'recalculated_series' => 0];
        }

        $queued = self::enqueue_rule_rebuild();
        self::log_change('rule_settings_updated', sprintf('Rule settings %s and queued %d symbol/timeframe series for background recalculation.', $current === $sanitized ? 'executed' : 'updated', $queued));

        return ['updated' => $current !== $sanitized, 'recalculated_series' => $queued, 'queued' => $queued];
    }

    public static function get_rule_rebuild_status() {
        $status = get_option(self::RULE_REBUILD_STATUS_OPTION, []);
        if (!is_array($status)) {
            $status = [];
        }

        $defaults = [
            'status' => 'idle',
            'total' => 0,
            'processed' => 0,
            'updated_at' => current_time('mysql'),
        ];

        $normalized = array_merge($defaults, $status);
        $normalized['total'] = max(0, (int) $normalized['total']);
        $normalized['processed'] = max(0, min((int) $normalized['processed'], (int) $normalized['total']));
        $normalized['progress_percent'] = self::calculate_rule_rebuild_progress($normalized['processed'], $normalized['total']);

        return $normalized;
    }

    public static function enqueue_rule_rebuild() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $all_series = $wpdb->get_results("SELECT DISTINCT symbol, timeframe FROM {$table}", ARRAY_A);
        $tasks = [];

        foreach ((array) $all_series as $series) {
            $symbol = strtoupper(trim((string) ($series['symbol'] ?? '')));
            $timeframe = strtoupper(trim((string) ($series['timeframe'] ?? '')));
            if ($symbol === '' || $timeframe === '') {
                continue;
            }

            $tasks[] = [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
            ];
        }

        update_option(self::RULE_REBUILD_TASKS_OPTION, $tasks, false);
        update_option(
            self::RULE_REBUILD_STATUS_OPTION,
            [
                'status' => empty($tasks) ? 'done' : 'running',
                'total' => count($tasks),
                'processed' => 0,
                'updated_at' => current_time('mysql'),
            ],
            false
        );

        if (!empty($tasks) && defined('LCNI_RULE_REBUILD_CRON_HOOK')) {
            wp_clear_scheduled_hook(LCNI_RULE_REBUILD_CRON_HOOK);
            wp_schedule_single_event(current_time('timestamp') + 1, LCNI_RULE_REBUILD_CRON_HOOK);
        }

        return count($tasks);
    }

    public static function process_rule_rebuild_batch($batch_size = self::RULE_REBUILD_BATCH_SIZE) {
        $batch_size = max(1, (int) $batch_size);
        $tasks = get_option(self::RULE_REBUILD_TASKS_OPTION, []);
        if (!is_array($tasks)) {
            $tasks = [];
        }

        if (empty($tasks)) {
            update_option(
                self::RULE_REBUILD_STATUS_OPTION,
                [
                    'status' => 'done',
                    'total' => 0,
                    'processed' => 0,
                    'updated_at' => current_time('mysql'),
                ],
                false
            );

            return ['processed_in_batch' => 0, 'remaining' => 0, 'done' => true];
        }

        $status = self::get_rule_rebuild_status();
        $processed = (int) $status['processed'];
        $processed_in_batch = 0;
        $touched_timeframes = [];

        while ($processed_in_batch < $batch_size && !empty($tasks)) {
            $task = array_shift($tasks);
            self::rebuild_ohlc_indicators($task['symbol'], $task['timeframe']);
            self::rebuild_ohlc_trading_index($task['symbol'], $task['timeframe']);
            $touched_timeframes[(string) $task['timeframe']] = true;
            $processed_in_batch++;
            $processed++;
        }

        if (!empty($touched_timeframes)) {
            $timeframes = array_keys($touched_timeframes);
            self::rebuild_rs_1m_by_exchange([], $timeframes);
            self::rebuild_rs_1w_by_exchange([], $timeframes);
            self::rebuild_rs_3m_by_exchange([], $timeframes);
            self::rebuild_rs_exchange_signals([], $timeframes);
        }

        update_option(self::RULE_REBUILD_TASKS_OPTION, $tasks, false);

        $done = empty($tasks);
        update_option(
            self::RULE_REBUILD_STATUS_OPTION,
            [
                'status' => $done ? 'done' : 'running',
                'total' => max((int) $status['total'], $processed + count($tasks)),
                'processed' => $processed,
                'updated_at' => current_time('mysql'),
            ],
            false
        );

        if ($done) {
            self::log_change('rule_rebuild_completed', sprintf('Background rule rebuild completed for %d symbol/timeframe series.', $processed));
            wp_clear_scheduled_hook(LCNI_RULE_REBUILD_CRON_HOOK);
        } elseif (defined('LCNI_RULE_REBUILD_CRON_HOOK')) {
            wp_schedule_single_event(current_time('timestamp') + 1, LCNI_RULE_REBUILD_CRON_HOOK);
        }

        return ['processed_in_batch' => $processed_in_batch, 'remaining' => count($tasks), 'done' => $done];
    }

    private static function calculate_rule_rebuild_progress($processed, $total) {
        $total = max(0, (int) $total);
        $processed = max(0, (int) $processed);
        if ($total === 0) {
            return 100;
        }

        return (int) floor(($processed / $total) * 100);
    }

    public static function rebuild_all_ohlc_metrics() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $all_series = $wpdb->get_results("SELECT DISTINCT symbol, timeframe FROM {$table}", ARRAY_A);

        if (empty($all_series)) {
            return 0;
        }

        foreach ($all_series as $series) {
            self::rebuild_ohlc_indicators($series['symbol'], $series['timeframe']);
            self::rebuild_ohlc_trading_index($series['symbol'], $series['timeframe']);
        }

        self::rebuild_rs_1m_by_exchange();
        self::rebuild_rs_1w_by_exchange();
        self::rebuild_rs_3m_by_exchange();
        self::rebuild_rs_exchange_signals();

        return count($all_series);
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
            self::rebuild_ohlc_trading_index($series['symbol'], $series['timeframe']);
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
            self::rebuild_ohlc_trading_index($series['symbol'], $series['timeframe']);
        }

        self::log_change(
            'backfill_ohlc_trading_index_xay_nen',
            sprintf('Backfilled trading_index/xay_nen for %d symbol/timeframe series with missing values.', count($series_with_missing_values))
        );
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_nen_type_metrics() {
        global $wpdb;

        $migration_flag = 'lcni_ohlc_nen_type_metrics_backfilled_v3';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        $table = $wpdb->prefix . 'lcni_ohlc';
        $series_with_missing_values = $wpdb->get_results(
            "SELECT DISTINCT symbol, timeframe
            FROM {$table}
            WHERE xay_nen_count_30 IS NULL
                OR nen_type IS NULL
                OR pha_nen IS NULL",
            ARRAY_A
        );

        if (empty($series_with_missing_values)) {
            update_option($migration_flag, 'yes');

            return;
        }

        foreach ($series_with_missing_values as $series) {
            self::rebuild_ohlc_indicators($series['symbol'], $series['timeframe']);
            self::rebuild_ohlc_trading_index($series['symbol'], $series['timeframe']);
        }

        self::log_change(
            'backfill_ohlc_nen_type_metrics',
            sprintf('Backfilled xay_nen_count_30/nen_type for %d symbol/timeframe series with missing values.', count($series_with_missing_values))
        );
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_pha_nen_metrics() {
        $migration_flag = 'lcni_ohlc_pha_nen_metrics_backfilled_v1';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        $recalculated_series = self::rebuild_all_ohlc_metrics();
        self::log_change('backfill_ohlc_pha_nen_metrics', sprintf('Backfilled pha_nen for %d symbol/timeframe series.', $recalculated_series));
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_tang_gia_kem_vol_metrics() {
        $migration_flag = 'lcni_ohlc_tang_gia_kem_vol_metrics_backfilled_v2';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        $recalculated_series = self::rebuild_all_ohlc_metrics();
        self::log_change('backfill_ohlc_tang_gia_kem_vol_metrics', sprintf('Backfilled tang_gia_kem_vol for %d symbol/timeframe series.', $recalculated_series));
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_smart_money_metrics() {
        $migration_flag = 'lcni_ohlc_smart_money_metrics_backfilled_v1';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        $recalculated_series = self::rebuild_all_ohlc_metrics();
        self::log_change('backfill_ohlc_smart_money_metrics', sprintf('Backfilled smart_money for %d symbol/timeframe series.', $recalculated_series));
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_rs_1m_by_exchange() {
        $migration_flag = 'lcni_ohlc_rs_1m_by_exchange_backfilled_v1';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        self::rebuild_rs_1m_by_exchange();
        self::log_change('backfill_ohlc_rs_1m_by_exchange', 'Backfilled rs_1m_by_exchange for OHLC rows by exchange ranking.');
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_rs_1w_by_exchange() {
        $migration_flag = 'lcni_ohlc_rs_1w_by_exchange_backfilled_v1';
        if (get_option($migration_flag) === 'yes') {
            return;
        }

        self::rebuild_rs_1w_by_exchange();
        self::log_change('backfill_ohlc_rs_1w_by_exchange', 'Backfilled rs_1w_by_exchange for OHLC rows by exchange ranking.');
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_rs_3m_by_exchange() {
        $migration_flag = 'lcni_ohlc_rs_3m_by_exchange_backfilled_v2';
        if (get_option($migration_flag) === 'yes' && !self::has_missing_rs_3m_by_exchange_rows()) {
            return;
        }

        self::rebuild_rs_3m_by_exchange();
        self::log_change('backfill_ohlc_rs_3m_by_exchange', 'Backfilled rs_3m_by_exchange for OHLC rows by exchange ranking.');
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_rs_exchange_signals() {
        $migration_flag = 'lcni_ohlc_rs_exchange_signals_backfilled_v2';
        if (get_option($migration_flag) === 'yes' && !self::has_missing_rs_exchange_signal_rows()) {
            return;
        }

        self::rebuild_rs_exchange_signals();
        self::log_change('backfill_ohlc_rs_exchange_signals', 'Backfilled rs_exchange_status and rs_exchange_recommend for OHLC rows.');
        update_option($migration_flag, 'yes');
    }

    private static function backfill_ohlc_rs_recommend_status() {
        $migration_flag = 'lcni_ohlc_rs_recommend_status_backfilled_v1';
        if (get_option($migration_flag) === 'yes' && !self::has_missing_rs_recommend_status_rows()) {
            return;
        }

        self::rebuild_rs_recommend_status();
        self::log_change('backfill_ohlc_rs_recommend_status', 'Backfilled rs_recommend_status from rs_exchange_status and rs_exchange_recommend.');
        update_option($migration_flag, 'yes');
    }

    private static function has_missing_rs_exchange_signal_rows() {
        global $wpdb;

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $missing_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
            FROM {$ohlc_table}
            WHERE rs_exchange_status IS NULL
                OR rs_exchange_recommend IS NULL"
        );

        return $missing_count > 0;
    }

    private static function has_missing_rs_recommend_status_rows() {
        global $wpdb;

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $missing_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
            FROM {$ohlc_table}
            WHERE rs_recommend_status IS NULL
                OR TRIM(rs_recommend_status) = ''"
        );

        return $missing_count > 0;
    }

    private static function has_missing_rs_3m_by_exchange_rows() {
        global $wpdb;

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $mapping_table = $wpdb->prefix . 'lcni_sym_icb_market';
        $missing_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
            FROM {$ohlc_table} o
            INNER JOIN {$mapping_table} m
                ON o.symbol = m.symbol
            WHERE o.volume >= 50000
                AND o.pct_3m IS NOT NULL
                AND o.rs_3m_by_exchange IS NULL"
        );

        return $missing_count > 0;
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

        $ohlc_summary = self::collect_ohlc_data($latest_only, $offset, $batch_limit);

        return [
            'ohlc' => $ohlc_summary,
        ];
    }

    public static function get_csv_import_targets() {
        return [
            'lcni_symbols' => [
                'label' => 'LCNI Symbols',
                'primary_key' => 'symbol',
                'columns' => [
                    'symbol' => 'text',
                    'market_id' => 'text',
                    'board_id' => 'text',
                    'isin' => 'text',
                    'product_grp_id' => 'text',
                    'security_group_id' => 'text',
                    'id_icb2' => 'int',
                    'basic_price' => 'float',
                    'ceiling_price' => 'float',
                    'floor_price' => 'float',
                    'open_interest_quantity' => 'int',
                    'security_status' => 'text',
                    'symbol_admin_status_code' => 'text',
                    'symbol_trading_method_status_code' => 'text',
                    'symbol_trading_sanction_status_code' => 'text',
                    'source' => 'text',
                ],
            ],
            'lcni_marketid' => [
                'label' => 'LCNI Market',
                'primary_key' => 'market_id',
                'columns' => [
                    'market_id' => 'text',
                    'exchange' => 'text',
                ],
            ],
            'lcni_icb2' => [
                'label' => 'LCNI ICB2',
                'primary_key' => 'id_icb2',
                'columns' => [
                    'id_icb2' => 'int',
                    'name_icb2' => 'text',
                ],
            ],
            'lcni_sym_icb_market' => [
                'label' => 'LCNI Symbol-Market-ICB Mapping',
                'primary_key' => 'symbol',
                'columns' => [
                    'symbol' => 'text',
                    'market_id' => 'text',
                    'id_icb2' => 'int',
                    'exchange' => 'text',
                ],
            ],
            'lcni_symbol_tongquan' => [
                'label' => 'LCNI Symbol Tổng quan',
                'primary_key' => 'symbol',
                'columns' => [
                    'symbol' => 'text',
                    'eps' => 'float',
                    'eps_1y_pct' => 'float',
                    'dt_1y_pct' => 'float',
                    'bien_ln_gop' => 'float',
                    'bien_ln_rong' => 'float',
                    'roe' => 'float',
                    'de_ratio' => 'float',
                    'pe_ratio' => 'float',
                    'pb_ratio' => 'float',
                    'ev_ebitda' => 'float',
                    'tcbs_khuyen_nghi' => 'text',
                    'co_tuc_pct' => 'float',
                    'tc_rating' => 'float',
                    'xep_hang' => 'text',
                    'so_huu_nn_pct' => 'float',
                    'tien_mat_rong_von_hoa' => 'float',
                    'tien_mat_rong_tong_tai_san' => 'float',
                    'loi_nhuan_4_quy_gan_nhat' => 'float',
                    'tang_truong_dt_quy_gan_nhat' => 'float',
                    'tang_truong_dt_quy_gan_nhi' => 'float',
                    'tang_truong_ln_quy_gan_nhat' => 'float',
                    'tang_truong_ln_quy_gan_nhi' => 'float',
                ],
            ],
        ];
    }

    public static function detect_csv_columns($file_path) {
        if (!is_readable($file_path)) {
            return new WP_Error('csv_not_readable', 'Không thể đọc file CSV đã upload.');
        }

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return new WP_Error('csv_open_failed', 'Không thể mở file CSV.');
        }

        $header = fgetcsv($handle);
        fclose($handle);

        if (!is_array($header) || empty($header)) {
            return new WP_Error('csv_empty', 'CSV không có dòng tiêu đề hợp lệ.');
        }

        $headers = [];
        foreach ($header as $index => $name) {
            $headers[] = [
                'index' => $index,
                'raw' => trim((string) $name),
                'normalized' => self::normalize_csv_header($name),
            ];
        }

        return $headers;
    }

    public static function suggest_csv_mapping($table_key, $headers) {
        $targets = self::get_csv_import_targets();
        if (!isset($targets[$table_key])) {
            return [];
        }

        $target_columns = array_keys($targets[$table_key]['columns']);
        $mapping = [];

        foreach ($headers as $header) {
            $source = (string) ($header['normalized'] ?? '');
            if ($source === '') {
                continue;
            }

            if (in_array($source, $target_columns, true)) {
                $mapping[$source] = $source;
                continue;
            }

            if ($table_key === 'lcni_symbol_tongquan') {
                $aliases = [
                    'mck' => 'symbol',
                    'ma_ck' => 'symbol',
                    'pe' => 'pe_ratio',
                    'p_e' => 'pe_ratio',
                    'pb' => 'pb_ratio',
                    'p_b' => 'pb_ratio',
                    'de' => 'de_ratio',
                    'd_e' => 'de_ratio',
                    'evebitda' => 'ev_ebitda',
                    'ev_ebitda' => 'ev_ebitda',
                    'tcbs_khuyen_nghi' => 'tcbs_khuyen_nghi',
                    'tcbskhuyennghi' => 'tcbs_khuyen_nghi',
                    'tc_rating' => 'tc_rating',
                    'xep_hang' => 'xep_hang',
                    'eps_1_nam' => 'eps_1y_pct',
                    'eps_1y' => 'eps_1y_pct',
                    'dt_1_nam' => 'dt_1y_pct',
                    'doanh_thu_1_nam' => 'dt_1y_pct',
                    'bien_ln_gop' => 'bien_ln_gop',
                    'bien_ln_rong' => 'bien_ln_rong',
                    'co_tuc' => 'co_tuc_pct',
                    'co_tuc_pct' => 'co_tuc_pct',
                    'so_huu_nn' => 'so_huu_nn_pct',
                    'so_huu_nn_pct' => 'so_huu_nn_pct',
                    'tien_mat_rong_von_hoa' => 'tien_mat_rong_von_hoa',
                    'tien_mat_rong_tong_tai_san' => 'tien_mat_rong_tong_tai_san',
                    'loi_nhuan_4_quy_gan_nhat' => 'loi_nhuan_4_quy_gan_nhat',
                    'tang_truong_doanh_thu_quy_gan_nhat' => 'tang_truong_dt_quy_gan_nhat',
                    'tang_truong_doanh_thu_quy_gan_nhi' => 'tang_truong_dt_quy_gan_nhi',
                    'tang_truong_loi_nhuan_quy_gan_nhat' => 'tang_truong_ln_quy_gan_nhat',
                    'tang_truong_loi_nhuan_quy_gan_nhi' => 'tang_truong_ln_quy_gan_nhi',
                ];

                if (isset($aliases[$source])) {
                    $mapping[$source] = $aliases[$source];
                }
            }
        }

        return $mapping;
    }

    public static function import_csv_with_mapping($file_path, $table_key, $mapping) {
        self::ensure_tables_exist();

        $targets = self::get_csv_import_targets();
        if (!isset($targets[$table_key])) {
            return new WP_Error('invalid_table', 'Bảng import không hợp lệ.');
        }

        if (!is_readable($file_path)) {
            return new WP_Error('csv_not_readable', 'Không thể đọc file CSV đã upload.');
        }

        $target = $targets[$table_key];
        $columns = $target['columns'];
        $primary_key = $target['primary_key'];
        $primary_key_format = self::get_wpdb_format_for_type((string) ($columns[$primary_key] ?? 'text'));

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return new WP_Error('csv_open_failed', 'Không thể mở file CSV.');
        }

        $header = fgetcsv($handle);
        if (!is_array($header) || empty($header)) {
            fclose($handle);
            return new WP_Error('csv_empty', 'CSV không có dòng tiêu đề hợp lệ.');
        }

        $header_lookup = [];
        foreach ($header as $index => $name) {
            $normalized = self::normalize_csv_header($name);
            if ($normalized !== '') {
                $header_lookup[$normalized] = $index;
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . $table_key;
        $updated = 0;
        $total = 0;

        while (($line = fgetcsv($handle)) !== false) {
            if (!is_array($line) || empty($line)) {
                continue;
            }

            $record = [];
            $formats = [];

            foreach ((array) $mapping as $csv_column => $db_column) {
                $csv_column = self::normalize_csv_header($csv_column);
                $db_column = sanitize_key((string) $db_column);

                if ($csv_column === '' || $db_column === '' || !isset($columns[$db_column]) || !isset($header_lookup[$csv_column])) {
                    continue;
                }

                $value = trim((string) ($line[$header_lookup[$csv_column]] ?? ''));
                $parsed = self::cast_import_value($value, $columns[$db_column]);
                if ($db_column === 'symbol' && $parsed['value'] !== null) {
                    $parsed['value'] = strtoupper((string) $parsed['value']);
                }
                $record[$db_column] = $parsed['value'];
                $formats[$db_column] = $parsed['format'];
            }

            if (!isset($record[$primary_key]) || $record[$primary_key] === '' || $record[$primary_key] === null) {
                continue;
            }

            $existing_key = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT {$primary_key} FROM {$table} WHERE {$primary_key} = {$primary_key_format} LIMIT 1",
                    $record[$primary_key]
                )
            );

            if ($existing_key !== null) {
                $record_to_update = $record;
                $formats_to_update = $formats;
                unset($record_to_update[$primary_key], $formats_to_update[$primary_key]);

                if (empty($record_to_update)) {
                    $updated++;
                    continue;
                }

                $result = $wpdb->update(
                    $table,
                    $record_to_update,
                    [$primary_key => $record[$primary_key]],
                    array_values($formats_to_update),
                    [$primary_key_format]
                );
            } else {
                $result = $wpdb->insert($table, $record, array_values($formats));
            }

            if ($result !== false) {
                $updated++;
            }
            $total++;
        }

        fclose($handle);

        if ($table_key === 'lcni_symbols') {
            self::sync_symbol_market_icb_mapping();
        }

        if ($table_key === 'lcni_symbols' || $table_key === 'lcni_symbol_tongquan') {
            self::sync_symbol_tongquan_with_symbols();
        }

        if ($table_key === 'lcni_symbol_tongquan') {
            self::refresh_symbol_tongquan_rankings();
        }

        self::log_change('import_csv_generic', sprintf('Imported %d/%d rows into %s.', $updated, $total, $table_key), [
            'table' => $table_key,
            'mapping' => $mapping,
        ]);

        return [
            'updated' => $updated,
            'total' => $total,
            'table' => $table_key,
        ];
    }

    public static function import_symbols_from_csv($file_path) {
        $headers = self::detect_csv_columns($file_path);
        if (is_wp_error($headers)) {
            return $headers;
        }

        $mapping = self::suggest_csv_mapping('lcni_symbols', $headers);

        return self::import_csv_with_mapping($file_path, 'lcni_symbols', $mapping);
    }


    private static function cast_import_value($value, $type) {
        $value = trim((string) $value);
        if ($value === '') {
            return ['value' => null, 'format' => '%s'];
        }

        if ($type === 'text') {
            return ['value' => sanitize_text_field($value), 'format' => '%s'];
        }

        if ($type === 'int') {
            $normalized = preg_replace('/[^0-9\-]/', '', $value);
            return ['value' => $normalized === '' ? null : (int) $normalized, 'format' => '%d'];
        }

        $normalized = str_replace([' ', '%'], '', $value);
        if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
            $normalized = str_replace(',', '', $normalized);
        } elseif (strpos($normalized, ',') !== false) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return ['value' => is_numeric($normalized) ? (float) $normalized : null, 'format' => '%f'];
    }

    private static function get_wpdb_format_for_type($type) {
        if ($type === 'int') {
            return '%d';
        }

        if ($type === 'float') {
            return '%f';
        }

        return '%s';
    }

    private static function sync_symbol_tongquan_with_symbols() {
        global $wpdb;

        $symbols_table = $wpdb->prefix . 'lcni_symbols';
        $tongquan_table = $wpdb->prefix . 'lcni_symbol_tongquan';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tongquan_table)) !== $tongquan_table) {
            return;
        }

        $wpdb->query(
            "INSERT INTO {$tongquan_table} (symbol)
            SELECT s.symbol
            FROM {$symbols_table} s
            WHERE s.symbol <> ''
            ON DUPLICATE KEY UPDATE updated_at = {$tongquan_table}.updated_at"
        );

        self::refresh_symbol_tongquan_rankings();
    }

    private static function refresh_symbol_tongquan_rankings() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_symbol_tongquan';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        $wpdb->query(
            "UPDATE {$table}
            SET xep_hang =
                CASE
                    WHEN tc_rating >= 4 THEN 'A++'
                    WHEN tc_rating >= 3.5 THEN 'A+'
                    WHEN tc_rating >= 3 THEN 'A'
                    WHEN tc_rating >= 2.5 THEN 'B+'
                    WHEN tc_rating >= 2 THEN 'B'
                    WHEN tc_rating >= 1.5 THEN 'C+'
                    WHEN tc_rating >= 1 THEN 'C'
                    WHEN tc_rating >= 0.3 THEN 'D'
                    ELSE 'Chưa xếp hạng'
                END"
        );
    }

    public static function collect_ohlc_data($latest_only = false, $offset = 0, $batch_limit = self::SYMBOL_BATCH_LIMIT) {
        self::ensure_tables_exist();

        global $wpdb;

        $timeframe = strtoupper((string) get_option('lcni_timeframe', '1D'));
        $days = max(1, (int) get_option('lcni_days_to_load', 365));

        $batch_limit = max(1, (int) $batch_limit);
        $offset = max(0, (int) $offset);

        $symbol_table = $wpdb->prefix . 'lcni_symbol_tongquan';
        $total_symbols = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$symbol_table}");

        $symbols = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT symbol FROM {$symbol_table} ORDER BY symbol ASC LIMIT %d OFFSET %d",
                $batch_limit,
                $offset
            )
        );

        if (empty($symbols)) {
            self::log_change('sync_skipped', 'No symbols available in lcni_symbol_tongquan table for OHLC sync.');

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
                    $to_timestamp = current_time('timestamp');
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
                        $event_time = false;
                        if (!empty($row['candle_time'])) {
                            try {
                                $event_time = (new DateTimeImmutable((string) $row['candle_time'], wp_timezone()))->getTimestamp();
                            } catch (Exception $e) {
                                $event_time = false;
                            }
                        }

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

    public static function collect_intraday_data() {
        self::ensure_tables_exist();

        $started_at_ts = current_time('timestamp');
        $started_at_mysql = current_time('mysql');

        if (!self::is_trading_session_open()) {
            self::log_change('intraday_skipped', 'Intraday update skipped because market is out of trading session.');

            return [
                'processed_symbols' => 0,
                'success_symbols' => 0,
                'error_symbols' => 0,
                'pending_symbols' => 0,
                'total_symbols' => 0,
                'changed_symbols' => 0,
                'indicators_done' => true,
                'waiting_for_trading_session' => true,
                'message' => 'Waiting for trading session.',
                'error' => '',
                'started_at' => $started_at_mysql,
                'ended_at' => current_time('mysql'),
                'execution_seconds' => 0,
            ];
        }

        global $wpdb;

        $timeframe = strtoupper((string) get_option('lcni_timeframe', '1D'));
        $symbols = $wpdb->get_col("SELECT symbol FROM {$wpdb->prefix}lcni_symbol_tongquan");

        $symbols = array_values(array_unique(array_filter(array_map(static function ($symbol) {
            $value = strtoupper(trim((string) $symbol));
            return $value;
        }, (array) $symbols))));

        $total_symbols = count($symbols);
        if ($total_symbols === 0) {
            return [
                'processed_symbols' => 0,
                'success_symbols' => 0,
                'error_symbols' => 0,
                'pending_symbols' => 0,
                'total_symbols' => 0,
                'changed_symbols' => 0,
                'indicators_done' => true,
                'message' => 'Không có symbol để cập nhật.',
                'started_at' => $started_at_mysql,
                'ended_at' => current_time('mysql'),
                'execution_seconds' => max(0, current_time('timestamp') - $started_at_ts),
            ];
        }

        $current_timestamp = current_time('timestamp');
        $from_timestamp = max(1, $current_timestamp - DAY_IN_SECONDS);
        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $processed_symbols = 0;
        $success_symbols = 0;
        $error_symbols = 0;

        foreach ($symbols as $symbol) {
            $url = add_query_arg(
                [
                    'symbol' => $symbol,
                    'resolution' => $timeframe,
                    'from' => $from_timestamp,
                    'to' => $current_timestamp,
                ],
                LCNI_API::CANDLE_URL
            );

            $response = wp_remote_get($url, [
                'timeout' => 20,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            if (is_wp_error($response)) {
                $error_symbols++;
                $processed_symbols++;
                usleep(100000);
                continue;
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                $error_symbols++;
                $processed_symbols++;
                usleep(100000);
                continue;
            }

            $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($decoded) || !isset($decoded['s']) || $decoded['s'] !== 'ok') {
                $error_symbols++;
                $processed_symbols++;
                usleep(100000);
                continue;
            }

            $rows = lcni_convert_candles($decoded, $symbol, $timeframe);
            if (empty($rows)) {
                $error_symbols++;
                $processed_symbols++;
                usleep(100000);
                continue;
            }

            $latest_row = end($rows);
            if (!is_array($latest_row) || empty($latest_row['event_time'])) {
                $error_symbols++;
                $processed_symbols++;
                usleep(100000);
                continue;
            }

            $wpdb->update(
                $ohlc_table,
                [
                    'open_price' => self::normalize_price($latest_row['open'] ?? 0),
                    'high_price' => self::normalize_price($latest_row['high'] ?? 0),
                    'low_price' => self::normalize_price($latest_row['low'] ?? 0),
                    'close_price' => self::normalize_price($latest_row['close'] ?? 0),
                    'updated_at' => current_time('mysql'),
                ],
                [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'event_time' => (int) $latest_row['event_time'],
                ],
                ['%f', '%f', '%f', '%f', '%s'],
                ['%s', '%s', '%d']
            );

            if ((int) $wpdb->rows_affected > 0) {
                $success_symbols++;
            } else {
                $error_symbols++;
            }

            $processed_symbols++;
            usleep(100000);
        }

        return [
            'processed_symbols' => $processed_symbols,
            'success_symbols' => $success_symbols,
            'error_symbols' => $error_symbols,
            'pending_symbols' => max(0, $total_symbols - $processed_symbols),
            'total_symbols' => $total_symbols,
            'changed_symbols' => $success_symbols,
            'indicators_done' => true,
            'message' => 'Cập nhật dữ liệu trong phiên hoàn tất.',
            'started_at' => $started_at_mysql,
            'ended_at' => current_time('mysql'),
            'execution_seconds' => max(0, current_time('timestamp') - $started_at_ts),
        ];
    }

    public static function is_trading_session_open() {
        return lcni_is_trading_time();
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

        $table = $wpdb->prefix . 'lcni_symbol_tongquan';

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
        $touched_event_times = [];
        $touched_timeframes = [];

        foreach ($rows as $row) {
            $event_time = isset($row['event_time']) ? (int) $row['event_time'] : 0;
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
            $touched_event_times[$record['event_time']] = true;
            $touched_timeframes[$record['timeframe']] = true;

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
            self::rebuild_ohlc_trading_index($series['symbol'], $series['timeframe']);
        }

        if (!empty($touched_series)) {
            self::rebuild_missing_ohlc_indicators(5);
            self::rebuild_rs_1m_by_exchange(array_keys($touched_event_times), array_keys($touched_timeframes));
            self::rebuild_rs_1w_by_exchange(array_keys($touched_event_times), array_keys($touched_timeframes));
            // pct_3m values are recalculated for the full symbol/timeframe series above.
            // Rebuild RS 3M for the whole timeframe scope so rows that become eligible
            // after historical backfills are not left NULL.
            self::rebuild_rs_3m_by_exchange([], array_keys($touched_timeframes));
            self::rebuild_rs_exchange_signals([], array_keys($touched_timeframes));
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
                    OR latest.xay_nen IS NULL
                    OR latest.xay_nen_count_30 IS NULL
                    OR latest.nen_type IS NULL
                    OR latest.pha_nen IS NULL
                    OR latest.tang_gia_kem_vol IS NULL
                    OR latest.smart_money IS NULL
                    OR latest.rs_exchange_status IS NULL
                    OR latest.rs_exchange_recommend IS NULL
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if (empty($missing_series)) {
            return 0;
        }

        $event_times = [];
        $timeframes = [];

        foreach ($missing_series as $series) {
            self::rebuild_ohlc_indicators($series['symbol'], $series['timeframe']);
            self::rebuild_ohlc_trading_index($series['symbol'], $series['timeframe']);
            $timeframes[$series['timeframe']] = true;

            $series_event_times = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT event_time
                    FROM {$table}
                    WHERE symbol = %s AND timeframe = %s",
                    $series['symbol'],
                    $series['timeframe']
                )
            );

            foreach ((array) $series_event_times as $event_time) {
                $event_times[(int) $event_time] = true;
            }
        }

        if (!empty($timeframes)) {
            self::rebuild_rs_1m_by_exchange(array_keys($event_times), array_keys($timeframes));
            self::rebuild_rs_1w_by_exchange(array_keys($event_times), array_keys($timeframes));
            self::rebuild_rs_3m_by_exchange(array_keys($event_times), array_keys($timeframes));
            self::rebuild_rs_exchange_signals(array_keys($event_times), array_keys($timeframes));
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
        $nen_types = [];
        $rules = self::get_rule_settings();
        $exchange = self::get_exchange_by_symbol($symbol);

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
                self::change_pct($closes, $i, 63),
                $rules
            );

            $xay_nen_flags[] = $xay_nen === 'xây nền' ? 1 : 0;
            $window_start = max(0, count($xay_nen_flags) - 30);
            $xay_nen_count_30 = array_sum(array_slice($xay_nen_flags, $window_start));
            $nen_type = self::determine_nen_type($xay_nen_count_30, $rules);
            $previous_nen_type = $i > 0 && isset($nen_types[$i - 1]) ? $nen_types[$i - 1] : null;
            $pct_t_1 = self::change_pct($closes, $i, 1);
            $vol_sv_vol_ma10 = self::safe_ratio_pct($volume, $vol_ma10);
            $vol_sv_vol_ma20 = self::safe_ratio_pct($volume, $vol_ma20);
            $pha_nen = self::determine_pha_nen($previous_nen_type, $pct_t_1, $vol_sv_vol_ma20, $rules);
            $tang_gia_kem_vol = self::determine_tang_gia_kem_vol(
                $exchange,
                $pct_t_1,
                self::ratio_from_ratio_pct($vol_sv_vol_ma10),
                self::ratio_from_ratio_pct($vol_sv_vol_ma20),
                $rules
            );
            $smart_money = self::determine_smart_money($symbol, $pha_nen, $tang_gia_kem_vol);
            $nen_types[] = $nen_type;

            $wpdb->update(
                $table,
                [
                    'trading_index' => $i + 1,
                    'pct_t_1' => $pct_t_1,
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
                    'vol_sv_vol_ma10' => $vol_sv_vol_ma10,
                    'vol_sv_vol_ma20' => $vol_sv_vol_ma20,
                    'macd' => $macd,
                    'macd_signal' => $signal,
                    'rsi' => $rsi,
                    'xay_nen' => $xay_nen,
                    'xay_nen_count_30' => $xay_nen_count_30,
                    'nen_type' => $nen_type,
                    'pha_nen' => $pha_nen,
                    'tang_gia_kem_vol' => $tang_gia_kem_vol,
                    'smart_money' => $smart_money,
                ],
                ['id' => (int) $rows[$i]['id']],
                ['%d','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%f','%s','%d','%s','%s','%s','%s'],
                ['%d']
            );
        }
    }


    private static function rebuild_rs_1m_by_exchange($event_times = [], $timeframes = []) {
        self::rebuild_rs_by_exchange('pct_1m', 'rs_1m_by_exchange', $event_times, $timeframes);
    }

    private static function rebuild_rs_1w_by_exchange($event_times = [], $timeframes = []) {
        self::rebuild_rs_by_exchange('pct_1w', 'rs_1w_by_exchange', $event_times, $timeframes);
    }

    private static function rebuild_rs_3m_by_exchange($event_times = [], $timeframes = []) {
        self::rebuild_rs_by_exchange('pct_3m', 'rs_3m_by_exchange', $event_times, $timeframes);
    }

    private static function rebuild_rs_exchange_signals($event_times = [], $timeframes = []) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $rules = self::get_rule_settings();
        $params = [
            (float) $rules['rs_exchange_status_song_manh_1w_min'],
            (float) $rules['rs_exchange_status_song_manh_1m_min'],
            (float) $rules['rs_exchange_status_song_manh_3m_max'],
            (float) $rules['rs_exchange_status_giu_trend_1w_min'],
            (float) $rules['rs_exchange_status_giu_trend_1m_min'],
            (float) $rules['rs_exchange_status_giu_trend_3m_min'],
            (float) $rules['rs_exchange_status_yeu_1w_max'],
            (float) $rules['rs_exchange_status_yeu_1m_max'],
            (float) $rules['rs_exchange_status_yeu_3m_max'],
            (float) $rules['rs_exchange_recommend_volume_min'],
            (float) $rules['rs_exchange_recommend_buy_1w_min'],
            (float) $rules['rs_exchange_recommend_buy_1w_gain_over_1m'],
            (float) $rules['rs_exchange_recommend_buy_pct_1w_min'],
            (float) $rules['rs_exchange_recommend_buy_pct_1m_max'],
            (float) $rules['rs_exchange_recommend_buy_pct_3m_max'],
            (float) $rules['rs_exchange_recommend_buy_pct_t_1_min'],
            (float) $rules['rs_exchange_recommend_buy_volume_boost_ratio'],
            (float) $rules['rs_exchange_recommend_sell_1w_max'],
            (float) $rules['rs_exchange_recommend_sell_pct_1w_max'],
        ];

        $where_clause = '';
        if (!empty($event_times) || !empty($timeframes)) {
            $filters = [];
            if (!empty($event_times)) {
                $filters[] = 'cur.event_time IN (' . implode(', ', array_fill(0, count($event_times), '%d')) . ')';
                $params = array_merge($params, array_map('intval', $event_times));
            }

            if (!empty($timeframes)) {
                $filters[] = 'cur.timeframe IN (' . implode(', ', array_fill(0, count($timeframes), '%s')) . ')';
                $params = array_merge($params, $timeframes);
            }

            if (!empty($filters)) {
                $where_clause = ' WHERE ' . implode(' AND ', $filters);
            }
        }

        $sql = "UPDATE {$table} cur
            LEFT JOIN {$table} prev
                ON prev.symbol = cur.symbol
                AND prev.timeframe = cur.timeframe
                AND prev.trading_index = cur.trading_index - 1
            SET
                cur.rs_exchange_status =
                    CASE
                        WHEN cur.rs_1w_by_exchange >= %f
                            AND cur.rs_1m_by_exchange >= %f
                            AND cur.rs_3m_by_exchange < %f
                            THEN 'RS -> Vào Sóng Mạnh'
                        WHEN cur.rs_1w_by_exchange >= %f
                            AND cur.rs_1m_by_exchange >= %f
                            AND cur.rs_3m_by_exchange >= %f
                            THEN 'RS -> Giữ Trend Mạnh'
                        WHEN cur.rs_1w_by_exchange > cur.rs_1m_by_exchange
                            AND cur.rs_1m_by_exchange > cur.rs_3m_by_exchange
                            THEN 'RS -> Tăng dần ổn'
                        WHEN cur.rs_1w_by_exchange < cur.rs_1m_by_exchange
                            AND cur.rs_1m_by_exchange < cur.rs_3m_by_exchange
                            THEN 'RS -> Đảo chiều giảm'
                        WHEN cur.rs_1w_by_exchange < %f
                            AND cur.rs_1m_by_exchange < %f
                            AND cur.rs_3m_by_exchange < %f
                            THEN 'RS -> Yếu'
                        ELSE 'RS -> Theo dõi'
                    END,
                cur.rs_exchange_recommend =
                    CASE
                        WHEN cur.volume >= %f
                            AND cur.rs_1w_by_exchange > %f
                            AND cur.rs_1w_by_exchange > cur.rs_1m_by_exchange + %f
                            AND cur.rs_1w_by_exchange > IFNULL(prev.rs_1w_by_exchange, 0)
                            AND cur.rs_1m_by_exchange > IFNULL(prev.rs_1m_by_exchange, 0)
                            AND cur.pct_1w >= %f
                            AND NOT (cur.pct_1m > %f OR cur.pct_3m > %f)
                            AND (
                                cur.pct_t_1 > %f
                                OR cur.volume > (%f * cur.vol_ma20)
                            )
                            THEN 'RS -> Gợi ý Mua'
                        WHEN cur.rs_1w_by_exchange < cur.rs_1m_by_exchange
                            AND cur.rs_1w_by_exchange < %f
                            AND cur.pct_1w < %f
                            THEN 'RS->Gợi ý Bán'
                        ELSE 'RS->Theo dõi'
                    END{$where_clause}";

        $wpdb->query($wpdb->prepare($sql, $params));
        self::rebuild_rs_recommend_status($event_times, $timeframes);
    }

    private static function rebuild_rs_recommend_status($event_times = [], $timeframes = []) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';

        $event_times = array_values(array_filter(array_map('intval', (array) $event_times), function ($value) {
            return $value > 0;
        }));
        $timeframes = array_values(array_filter(array_map(function ($timeframe) {
            return strtoupper(sanitize_text_field((string) $timeframe));
        }, (array) $timeframes)));

        $params = [];
        $filters = [];

        if (!empty($event_times)) {
            $filters[] = 'event_time IN (' . implode(', ', array_fill(0, count($event_times), '%d')) . ')';
            $params = array_merge($params, array_map('intval', $event_times));
        }

        if (!empty($timeframes)) {
            $filters[] = 'timeframe IN (' . implode(', ', array_fill(0, count($timeframes), '%s')) . ')';
            $params = array_merge($params, $timeframes);
        }

        $where_clause = !empty($filters) ? ' WHERE ' . implode(' AND ', $filters) : '';

        $sql = "UPDATE {$table}
            SET rs_recommend_status =
                CASE
                    WHEN LOWER(REPLACE(IFNULL(rs_exchange_status, ''), ' ', '')) IN ('rs->vaosongmanh', 'rs->vàosóngmạnh')
                        AND LOWER(REPLACE(IFNULL(rs_exchange_recommend, ''), ' ', '')) IN ('rs->goiymua', 'rs->gợiýmua')
                        THEN 'Dẫn dắt – Vào sóng sớm'
                    WHEN LOWER(REPLACE(IFNULL(rs_exchange_status, ''), ' ', '')) IN ('rs->giutrendmanh', 'rs->giữtrendmạnh')
                        AND LOWER(REPLACE(IFNULL(rs_exchange_recommend, ''), ' ', '')) IN ('rs->goiymua', 'rs->gợiýmua')
                        THEN 'Dẫn dắt – Tiếp diễn xu hướng'
                    WHEN LOWER(REPLACE(IFNULL(rs_exchange_status, ''), ' ', '')) IN ('rs->tangdanon', 'rs->tăngdầnổn')
                        AND LOWER(REPLACE(IFNULL(rs_exchange_recommend, ''), ' ', '')) IN ('rs->goiymua', 'rs->gợiýmua')
                        THEN 'Cơ hội mua – Đang hình thành'
                    WHEN LOWER(REPLACE(IFNULL(rs_exchange_status, ''), ' ', '')) IN ('rs->daochieugiam', 'rs->đảochiềugiảm')
                        AND LOWER(REPLACE(IFNULL(rs_exchange_recommend, ''), ' ', '')) IN ('rs->goiyban', 'rs->gợiýbán')
                        THEN 'Suy yếu – Mất sức mạnh'
                    WHEN LOWER(REPLACE(IFNULL(rs_exchange_status, ''), ' ', '')) IN ('rs->yeu', 'rs->yếu')
                        AND LOWER(REPLACE(IFNULL(rs_exchange_recommend, ''), ' ', '')) IN ('rs->goiyban', 'rs->gợiýbán')
                        THEN 'Tránh – Rất yếu'
                    ELSE 'Theo dõi'
                END{$where_clause}";

        if (empty($params)) {
            $wpdb->query($sql);

            return;
        }

        $wpdb->query($wpdb->prepare($sql, $params));
    }

    private static function rebuild_rs_by_exchange($source_column, $target_column, $event_times = [], $timeframes = []) {
        global $wpdb;

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $mapping_table = $wpdb->prefix . 'lcni_sym_icb_market';
        $source_column = sanitize_key((string) $source_column);
        $target_column = sanitize_key((string) $target_column);

        if (!in_array($source_column, ['pct_1m', 'pct_1w', 'pct_3m'], true)) {
            return;
        }

        if (!in_array($target_column, ['rs_1m_by_exchange', 'rs_1w_by_exchange', 'rs_3m_by_exchange'], true)) {
            return;
        }

        $event_times = array_values(array_filter(array_map('intval', (array) $event_times), function ($value) {
            return $value > 0;
        }));
        $timeframes = array_values(array_filter(array_map(function ($timeframe) {
            return strtoupper(sanitize_text_field((string) $timeframe));
        }, (array) $timeframes)));

        $filters = [];
        $params = [];

        if (!empty($event_times)) {
            $filters[] = 'o2.event_time IN (' . implode(', ', array_fill(0, count($event_times), '%d')) . ')';
            $params = array_merge($params, $event_times);
        }

        if (!empty($timeframes)) {
            $filters[] = 'o2.timeframe IN (' . implode(', ', array_fill(0, count($timeframes), '%s')) . ')';
            $params = array_merge($params, $timeframes);
        }

        $where_clause = empty($filters) ? '' : (' AND ' . implode(' AND ', $filters));

        $sql = "UPDATE {$ohlc_table} o
            JOIN {$mapping_table} m
                ON o.symbol = m.symbol
            LEFT JOIN (
                SELECT
                    o2.event_time,
                    o2.timeframe,
                    m2.exchange,
                    o2.symbol,
                    RANK() OVER (
                        PARTITION BY o2.event_time, o2.timeframe, m2.exchange
                        ORDER BY o2.{$source_column} DESC
                    ) AS rank_val,
                    COUNT(*) OVER (
                        PARTITION BY o2.event_time, o2.timeframe, m2.exchange
                    ) AS total_rows
                FROM {$ohlc_table} o2
                JOIN {$mapping_table} m2
                    ON o2.symbol = m2.symbol
                WHERE o2.volume >= 50000
                  AND o2.{$source_column} IS NOT NULL{$where_clause}
            ) r
                ON o.event_time = r.event_time
                AND o.timeframe = r.timeframe
                AND o.symbol = r.symbol
                AND m.exchange = r.exchange
            SET o.{$target_column} =
                CASE
                    WHEN o.volume >= 50000 THEN
                        CASE
                            WHEN r.total_rows <= 1 THEN 100
                            ELSE ROUND((1 - ((r.rank_val - 1) / (r.total_rows - 1))) * 100)
                        END
                    ELSE NULL
                END";

        if (!empty($event_times) || !empty($timeframes)) {
            $outer_filters = [];

            if (!empty($event_times)) {
                $outer_filters[] = 'o.event_time IN (' . implode(', ', array_fill(0, count($event_times), '%d')) . ')';
                $params = array_merge($params, $event_times);
            }

            if (!empty($timeframes)) {
                $outer_filters[] = 'o.timeframe IN (' . implode(', ', array_fill(0, count($timeframes), '%s')) . ')';
                $params = array_merge($params, $timeframes);
            }

            if (!empty($outer_filters)) {
                $sql .= ' WHERE ' . implode(' AND ', $outer_filters);
            }
        }

        if (empty($params)) {
            $wpdb->query($sql);

            return;
        }

        $wpdb->query($wpdb->prepare($sql, $params));
    }

    private static function rebuild_ohlc_trading_index($symbol, $timeframe) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $symbol = strtoupper((string) $symbol);
        $timeframe = strtoupper((string) $timeframe);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE symbol = %s AND timeframe = %s ORDER BY event_time ASC, id ASC",
                $symbol,
                $timeframe
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

    private static function is_xay_nen($rsi, $gia_sv_ma10, $gia_sv_ma20, $gia_sv_ma50, $vol_sv_vol_ma20, $volume, $pct_t_1, $pct_1w, $pct_1m, $pct_3m, $rules) {
        if ($rsi === null || $gia_sv_ma10 === null || $gia_sv_ma20 === null || $gia_sv_ma50 === null || $vol_sv_vol_ma20 === null || $pct_t_1 === null || $pct_1w === null || $pct_1m === null || $pct_3m === null) {
            return 'chưa đủ dữ liệu';
        }

        if (
            $rsi >= $rules['xay_nen_rsi_min'] && $rsi <= $rules['xay_nen_rsi_max']
            && abs((float) $gia_sv_ma10) <= $rules['xay_nen_gia_sv_ma10_abs_max']
            && abs((float) $gia_sv_ma20) <= $rules['xay_nen_gia_sv_ma20_abs_max']
            && abs((float) $gia_sv_ma50) <= $rules['xay_nen_gia_sv_ma50_abs_max']
            && (float) $vol_sv_vol_ma20 <= $rules['xay_nen_vol_sv_vol_ma20_max']
            && (float) $volume >= $rules['xay_nen_volume_min']
            && (float) $pct_t_1 >= -$rules['xay_nen_pct_t_1_abs_max'] && (float) $pct_t_1 <= $rules['xay_nen_pct_t_1_abs_max']
            && (float) $pct_1w >= -$rules['xay_nen_pct_1w_abs_max'] && (float) $pct_1w <= $rules['xay_nen_pct_1w_abs_max']
            && (float) $pct_1m >= -$rules['xay_nen_pct_1m_abs_max'] && (float) $pct_1m <= $rules['xay_nen_pct_1m_abs_max']
            && (float) $pct_3m >= -$rules['xay_nen_pct_3m_abs_max'] && (float) $pct_3m <= $rules['xay_nen_pct_3m_abs_max']
        ) {
            return 'xây nền';
        }

        return 'không xây nền';
    }

    private static function determine_nen_type($xay_nen_count_30, $rules) {
        if ($xay_nen_count_30 >= $rules['nen_type_chat_min_count_30']) {
            return 'Nền chặt';
        }

        if ($xay_nen_count_30 >= $rules['nen_type_vua_min_count_30']) {
            return 'Nền vừa';
        }

        return 'Nền lỏng';
    }

    private static function determine_pha_nen($previous_nen_type, $pct_t_1, $vol_sv_vol_ma20, $rules) {
        if (!in_array($previous_nen_type, ['Nền vừa', 'Nền chặt'], true)) {
            return null;
        }

        if ($pct_t_1 === null || $vol_sv_vol_ma20 === null) {
            return null;
        }

        if ((float) $pct_t_1 > $rules['pha_nen_pct_t_1_min'] && (float) $vol_sv_vol_ma20 >= $rules['pha_nen_vol_sv_vol_ma20_min']) {
            return 'Phá nền';
        }

        return null;
    }

    private static function determine_tang_gia_kem_vol($exchange, $pct_t_1, $vol_ratio_ma10, $vol_ratio_ma20, $rules) {
        if ($exchange === '' || $pct_t_1 === null || $vol_ratio_ma10 === null || $vol_ratio_ma20 === null) {
            return null;
        }

        $pct_threshold_by_exchange = [
            'HOSE' => (float) $rules['tang_gia_kem_vol_hose_pct_t_1_min'],
            'HNX' => (float) $rules['tang_gia_kem_vol_hnx_pct_t_1_min'],
            'UPCOM' => (float) $rules['tang_gia_kem_vol_upcom_pct_t_1_min'],
        ];

        if (!array_key_exists($exchange, $pct_threshold_by_exchange)) {
            return null;
        }

        if (
            (float) $pct_t_1 >= $pct_threshold_by_exchange[$exchange]
            && (float) $vol_ratio_ma10 > (float) $rules['tang_gia_kem_vol_vol_ratio_ma10_min']
            && (float) $vol_ratio_ma20 > (float) $rules['tang_gia_kem_vol_vol_ratio_ma20_min']
        ) {
            return 'Tăng giá kèm Vol';
        }

        return null;
    }

    private static function ratio_from_ratio_pct($ratio_pct) {
        if ($ratio_pct === null) {
            return null;
        }

        return (float) $ratio_pct + 1;
    }

    private static function determine_smart_money($symbol, $pha_nen, $tang_gia_kem_vol) {
        if ($pha_nen !== 'Phá nền' || $tang_gia_kem_vol !== 'Tăng giá kèm Vol') {
            return null;
        }

        $xep_hang = self::get_symbol_xep_hang($symbol);
        if (in_array($xep_hang, ['A++', 'A+', 'A', 'B+'], true)) {
            return 'Smart Money';
        }

        return null;
    }

    private static function get_symbol_xep_hang($symbol) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_symbol_tongquan';
        $symbol = strtoupper((string) $symbol);
        if ($symbol === '') {
            return '';
        }

        $xep_hang = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT xep_hang FROM {$table} WHERE symbol = %s LIMIT 1", $symbol)
        );

        return strtoupper(trim($xep_hang));
    }

    private static function get_exchange_by_symbol($symbol) {
        global $wpdb;

        $symbol = strtoupper((string) $symbol);
        if ($symbol === '') {
            return '';
        }

        if (array_key_exists($symbol, self::$symbol_exchange_cache)) {
            return self::$symbol_exchange_cache[$symbol];
        }

        $mapping_table = $wpdb->prefix . 'lcni_sym_icb_market';
        $exchange = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT exchange FROM {$mapping_table} WHERE symbol = %s LIMIT 1",
                $symbol
            )
        );

        if (trim($exchange) === '') {
            $symbols_table = $wpdb->prefix . 'lcni_symbols';
            $market_table = $wpdb->prefix . 'lcni_marketid';

            $exchange = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT m.exchange
                    FROM {$symbols_table} s
                    LEFT JOIN {$market_table} m ON m.market_id = s.market_id
                    WHERE s.symbol = %s
                    LIMIT 1",
                    $symbol
                )
            );
        }

        if (trim($exchange) === '') {
            $market_id = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT market_id FROM {$wpdb->prefix}lcni_symbols WHERE symbol = %s LIMIT 1",
                    $symbol
                )
            );

            foreach (self::DEFAULT_MARKETS as $market) {
                if ((string) $market['market_id'] === $market_id) {
                    $exchange = (string) $market['exchange'];
                    break;
                }
            }
        }

        $exchange = self::normalize_exchange($exchange);
        self::$symbol_exchange_cache[$symbol] = $exchange;

        return $exchange;
    }

    private static function normalize_exchange($exchange) {
        $exchange = strtoupper(trim((string) $exchange));
        if ($exchange === '') {
            return '';
        }

        $compact_exchange = str_replace([' ', '-', '_'], '', $exchange);
        $aliases = [
            'HSX' => 'HOSE',
            'HOSE' => 'HOSE',
            'HNX' => 'HNX',
            'HASTC' => 'HNX',
            'UPCOM' => 'UPCOM',
        ];

        if (array_key_exists($compact_exchange, $aliases)) {
            return $aliases[$compact_exchange];
        }

        return $exchange;
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
        self::sync_symbol_tongquan_with_symbols();

        return ['updated' => $updated];
    }

    private static function sync_symbol_market_icb_mapping() {
        global $wpdb;

        $symbols_table = $wpdb->prefix . 'lcni_symbols';
        $mapping_table = $wpdb->prefix . 'lcni_sym_icb_market';
        $market_table = $wpdb->prefix . 'lcni_marketid';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mapping_table)) !== $mapping_table) {
            return;
        }

        self::ensure_symbol_market_icb_columns();

        $wpdb->query(
            "INSERT INTO {$mapping_table} (symbol, market_id, id_icb2, exchange)
            SELECT s.symbol, s.market_id, s.id_icb2, UPPER(TRIM(COALESCE(m.exchange, '')))
            FROM {$symbols_table} s
            LEFT JOIN {$market_table} m ON m.market_id = s.market_id
            ON DUPLICATE KEY UPDATE
                market_id = CASE
                    WHEN VALUES(market_id) IS NULL OR VALUES(market_id) = '' THEN {$mapping_table}.market_id
                    ELSE VALUES(market_id)
                END,
                id_icb2 = COALESCE(VALUES(id_icb2), {$mapping_table}.id_icb2),
                exchange = CASE
                    WHEN VALUES(exchange) IS NULL OR VALUES(exchange) = '' THEN {$mapping_table}.exchange
                    ELSE VALUES(exchange)
                END,
                updated_at = CURRENT_TIMESTAMP"
        );

        self::$symbol_exchange_cache = [];
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

        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }

        $normalized = strtolower(trim($value));
        $normalized = str_replace([' ', '-', '.', '/', '\\'], '_', $normalized);
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized);
        $normalized = preg_replace('/_+/', '_', (string) $normalized);

        return trim((string) $normalized, '_');
    }

    private static function csv_col($line, $header_map, $keys) {
        foreach ($keys as $key) {
            if (isset($header_map[$key])) {
                return trim((string) ($line[$header_map[$key]] ?? ''));
            }
        }

        return null;
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

            if ($timestamp <= 0) {
                continue;
            }

            $rows[] = [
                'symbol' => strtoupper((string) $symbol),
                'timeframe' => strtoupper((string) $tf),
                'event_time' => $timestamp,
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
