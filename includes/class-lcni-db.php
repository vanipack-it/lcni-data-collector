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
