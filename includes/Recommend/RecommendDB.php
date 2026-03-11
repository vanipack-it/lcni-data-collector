<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Recommend_DB {

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $rule_table = $wpdb->prefix . 'lcni_recommend_rule';
        $signal_table = $wpdb->prefix . 'lcni_recommend_signal';
        $performance_table = $wpdb->prefix . 'lcni_recommend_performance';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$rule_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            timeframe VARCHAR(20) NOT NULL DEFAULT '1D',
            description TEXT NULL,
            entry_conditions LONGTEXT NOT NULL,
            initial_sl_pct DECIMAL(10,4) NOT NULL DEFAULT 8.0000,
            risk_reward DECIMAL(10,4) NOT NULL DEFAULT 3.0000,
            add_at_r DECIMAL(10,4) NOT NULL DEFAULT 2.0000,
            exit_at_r DECIMAL(10,4) NOT NULL DEFAULT 4.0000,
            max_hold_days INT UNSIGNED NOT NULL DEFAULT 20,
            apply_from_date DATE NULL,
            scan_time VARCHAR(5) NOT NULL DEFAULT '18:00',
            scan_times VARCHAR(100) NOT NULL DEFAULT '18:00',
            max_loss_pct DECIMAL(10,4) NOT NULL DEFAULT 8.0000,
            last_scan_at BIGINT UNSIGNED DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY is_active (is_active)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$signal_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            timeframe VARCHAR(20) NOT NULL DEFAULT '1D',
            entry_time BIGINT UNSIGNED NOT NULL,
            entry_price DECIMAL(20,4) NOT NULL,
            initial_sl DECIMAL(20,4) NOT NULL,
            risk_per_share DECIMAL(20,4) NOT NULL,
            current_price DECIMAL(20,4) NOT NULL,
            r_multiple DECIMAL(16,6) NOT NULL DEFAULT 0,
            position_state VARCHAR(40) NOT NULL DEFAULT 'EARLY',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            exit_price DECIMAL(20,4) DEFAULT NULL,
            exit_time BIGINT UNSIGNED DEFAULT NULL,
            final_r DECIMAL(16,6) DEFAULT NULL,
            holding_days INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_rule_symbol_tf_entry (rule_id, symbol, timeframe, entry_time),
            KEY rule_id (rule_id),
            KEY symbol_status (symbol, status),
            KEY status (status)
        ) {$charset_collate};");

        $log_table = $wpdb->prefix . 'lcni_recommend_rule_log';


        dbDelta("CREATE TABLE {$log_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            changed_by BIGINT UNSIGNED DEFAULT NULL,
            message TEXT NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY rule_action (rule_id, action)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$performance_table} (
            rule_id BIGINT UNSIGNED NOT NULL,
            total_trades INT UNSIGNED NOT NULL DEFAULT 0,
            win_trades INT UNSIGNED NOT NULL DEFAULT 0,
            lose_trades INT UNSIGNED NOT NULL DEFAULT 0,
            avg_r DECIMAL(16,6) NOT NULL DEFAULT 0,
            winrate DECIMAL(16,6) NOT NULL DEFAULT 0,
            expectancy DECIMAL(16,6) NOT NULL DEFAULT 0,
            max_r DECIMAL(16,6) DEFAULT NULL,
            min_r DECIMAL(16,6) DEFAULT NULL,
            avg_hold_days DECIMAL(16,6) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rule_id)
        ) {$charset_collate};");
    }

    public static function ensure_tables_exist() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'lcni_recommend_rule',
            $wpdb->prefix . 'lcni_recommend_signal',
            $wpdb->prefix . 'lcni_recommend_performance',
            $wpdb->prefix . 'lcni_recommend_rule_log',
        ];

        foreach ($tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                self::create_tables();
                return;
            }
        }

        $rule_table = $wpdb->prefix . 'lcni_recommend_rule';
        $description_exists = $wpdb->get_var("SHOW COLUMNS FROM {$rule_table} LIKE 'description'");
        if (!$description_exists) {
            $wpdb->query("ALTER TABLE {$rule_table} ADD COLUMN description TEXT NULL AFTER timeframe");
        }


        $apply_date_exists = $wpdb->get_var("SHOW COLUMNS FROM {$rule_table} LIKE 'apply_from_date'");
        if (!$apply_date_exists) {
            $wpdb->query("ALTER TABLE {$rule_table} ADD COLUMN apply_from_date DATE NULL AFTER max_hold_days");
        }

        $scan_time_exists = $wpdb->get_var("SHOW COLUMNS FROM {$rule_table} LIKE 'scan_time'");
        if (!$scan_time_exists) {
            $wpdb->query("ALTER TABLE {$rule_table} ADD COLUMN scan_time VARCHAR(5) NOT NULL DEFAULT '18:00' AFTER apply_from_date");
        }

        $last_scan_exists = $wpdb->get_var("SHOW COLUMNS FROM {$rule_table} LIKE 'last_scan_at'");
        if (!$last_scan_exists) {
            $wpdb->query("ALTER TABLE {$rule_table} ADD COLUMN last_scan_at BIGINT UNSIGNED DEFAULT NULL AFTER scan_time");
        }


        $scan_times_exists = $wpdb->get_var("SHOW COLUMNS FROM {$rule_table} LIKE 'scan_times'");
        if (!$scan_times_exists) {
            $wpdb->query("ALTER TABLE {$rule_table} ADD COLUMN scan_times VARCHAR(100) NOT NULL DEFAULT '18:00' AFTER scan_time");
            $wpdb->query("UPDATE {$rule_table} SET scan_times = scan_time WHERE scan_times = '' OR scan_times IS NULL");
        }

        $max_loss_exists = $wpdb->get_var("SHOW COLUMNS FROM {$rule_table} LIKE 'max_loss_pct'");
        if (!$max_loss_exists) {
            $wpdb->query("ALTER TABLE {$rule_table} ADD COLUMN max_loss_pct DECIMAL(10,4) NOT NULL DEFAULT 8.0000 AFTER initial_sl_pct");
            $wpdb->query("UPDATE {$rule_table} SET max_loss_pct = initial_sl_pct WHERE max_loss_pct <= 0");
        }

        $signal_table = $wpdb->prefix . 'lcni_recommend_signal';
        $timeframe_exists = $wpdb->get_var("SHOW COLUMNS FROM {$signal_table} LIKE 'timeframe'");
        if (!$timeframe_exists) {
            $wpdb->query("ALTER TABLE {$signal_table} ADD COLUMN timeframe VARCHAR(20) NOT NULL DEFAULT '1D' AFTER symbol");
            $wpdb->query(
                "UPDATE {$signal_table} s
                LEFT JOIN {$rule_table} r ON r.id = s.rule_id
                SET s.timeframe = COALESCE(NULLIF(r.timeframe, ''), '1D')"
            );
        }

        $unique_entry_exists = $wpdb->get_var("SHOW INDEX FROM {$signal_table} WHERE Key_name = 'uniq_rule_symbol_tf_entry'");
        if (!$unique_entry_exists) {
            $wpdb->query(
                "DELETE dup FROM {$signal_table} dup
                INNER JOIN {$signal_table} keep
                    ON keep.rule_id = dup.rule_id
                    AND keep.symbol = dup.symbol
                    AND keep.timeframe = dup.timeframe
                    AND keep.entry_time = dup.entry_time
                    AND keep.id < dup.id"
            );
            $wpdb->query("ALTER TABLE {$signal_table} ADD UNIQUE KEY uniq_rule_symbol_tf_entry (rule_id, symbol, timeframe, entry_time)");
        }
    }
}
