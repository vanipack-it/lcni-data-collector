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
            entry_conditions LONGTEXT NOT NULL,
            initial_sl_pct DECIMAL(10,4) NOT NULL DEFAULT 8.0000,
            risk_reward DECIMAL(10,4) NOT NULL DEFAULT 3.0000,
            add_at_r DECIMAL(10,4) NOT NULL DEFAULT 2.0000,
            exit_at_r DECIMAL(10,4) NOT NULL DEFAULT 4.0000,
            max_hold_days INT UNSIGNED NOT NULL DEFAULT 20,
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
            KEY rule_id (rule_id),
            KEY symbol_status (symbol, status),
            KEY status (status)
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
        ];

        foreach ($tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                self::create_tables();
                return;
            }
        }
    }
}
