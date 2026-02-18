<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_User_Features {

    public static function ensure_tables_exist() {
        global $wpdb;

        $watchlist_table = $wpdb->prefix . 'lcni_user_watchlist';
        $notification_table = $wpdb->prefix . 'lcni_signal_notifications';

        $watchlist_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $watchlist_table));
        $notification_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $notification_table));

        if ($watchlist_exists !== $watchlist_table || $notification_exists !== $notification_table) {
            self::create_tables();
        }
    }

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $watchlist_table = $wpdb->prefix . 'lcni_user_watchlist';
        $notification_table = $wpdb->prefix . 'lcni_signal_notifications';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_watchlist = "CREATE TABLE {$watchlist_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'manual',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_symbol (user_id, symbol),
            KEY idx_symbol (symbol),
            KEY idx_user_created (user_id, created_at)
        ) {$charset_collate};";

        $sql_notifications = "CREATE TABLE {$notification_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            signal_code VARCHAR(80) NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'in_app',
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            payload LONGTEXT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_user_read_created (user_id, is_read, created_at),
            KEY idx_symbol_signal (symbol, signal_code)
        ) {$charset_collate};";

        dbDelta($sql_watchlist);
        dbDelta($sql_notifications);
    }
}
