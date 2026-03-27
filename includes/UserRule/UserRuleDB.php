<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class UserRuleDB {

    public static function install( wpdb $wpdb ): void {
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── lcni_user_rules ──────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}lcni_user_rules (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id           BIGINT UNSIGNED NOT NULL,
            rule_id           BIGINT UNSIGNED NOT NULL,
            is_paper          TINYINT(1)     NOT NULL DEFAULT 1,
            capital           DECIMAL(20,4)  NOT NULL DEFAULT 0,
            risk_per_trade    DECIMAL(5,2)   NOT NULL DEFAULT 2.00,
            max_symbols       INT UNSIGNED   NOT NULL DEFAULT 5,
            start_date        DATE           NOT NULL,
            account_id        VARCHAR(60)    DEFAULT NULL,
            auto_order        TINYINT(1)     NOT NULL DEFAULT 0,
            symbol_scope      VARCHAR(20)    NOT NULL DEFAULT 'all',
            watchlist_id      BIGINT UNSIGNED DEFAULT NULL,
            custom_symbols    TEXT           DEFAULT NULL,
            status            VARCHAR(20)    NOT NULL DEFAULT 'active',
            created_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_rule (user_id, rule_id, is_paper),
            KEY user_id  (user_id),
            KEY rule_id  (rule_id),
            KEY status   (status)
        ) $charset;" );

        // ── lcni_user_signals ────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}lcni_user_signals (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_rule_id      BIGINT UNSIGNED NOT NULL,
            system_signal_id  BIGINT UNSIGNED NOT NULL,
            symbol            VARCHAR(20)    NOT NULL,
            entry_price       DECIMAL(20,4)  NOT NULL,
            entry_time        BIGINT UNSIGNED NOT NULL,
            exit_price        DECIMAL(20,4)  DEFAULT NULL,
            exit_time         BIGINT UNSIGNED DEFAULT NULL,
            initial_sl        DECIMAL(20,4)  NOT NULL DEFAULT 0,
            shares            INT UNSIGNED   NOT NULL DEFAULT 0,
            allocated_capital DECIMAL(20,4)  NOT NULL DEFAULT 0,
            current_price     DECIMAL(20,4)  NOT NULL DEFAULT 0,
            r_multiple        DECIMAL(16,6)  NOT NULL DEFAULT 0,
            final_r           DECIMAL(16,6)  DEFAULT NULL,
            pnl_vnd           DECIMAL(20,4)  DEFAULT NULL,
            holding_days      INT UNSIGNED   NOT NULL DEFAULT 0,
            position_state    VARCHAR(40)    NOT NULL DEFAULT 'EARLY',
            status            VARCHAR(20)    NOT NULL DEFAULT 'open',
            exit_reason       VARCHAR(30)    DEFAULT NULL,
            dnse_order_id     VARCHAR(100)   DEFAULT NULL,
            created_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_ur_sys (user_rule_id, system_signal_id),
            KEY user_rule_id (user_rule_id),
            KEY status (status)
        ) $charset;" );

        // ── lcni_user_performance ─────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$wpdb->prefix}lcni_user_performance (
            user_rule_id      BIGINT UNSIGNED NOT NULL,
            total_trades      INT UNSIGNED    NOT NULL DEFAULT 0,
            win_trades        INT UNSIGNED    NOT NULL DEFAULT 0,
            lose_trades       INT UNSIGNED    NOT NULL DEFAULT 0,
            total_r           DECIMAL(16,6)  NOT NULL DEFAULT 0,
            total_pnl_vnd     DECIMAL(20,4)  NOT NULL DEFAULT 0,
            current_capital   DECIMAL(20,4)  NOT NULL DEFAULT 0,
            max_drawdown_vnd  DECIMAL(20,4)  NOT NULL DEFAULT 0,
            max_drawdown_pct  DECIMAL(8,4)   NOT NULL DEFAULT 0,
            winrate           DECIMAL(8,4)   NOT NULL DEFAULT 0,
            updated_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_rule_id)
        ) $charset;" );
    }

    public static function ensure( wpdb $wpdb ): void {
        $t = $wpdb->prefix . 'lcni_user_rules';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            self::install( $wpdb );
            return;
        }
        // Migrate: add new columns if missing
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$t}`", 0 );
        if ( ! in_array( 'symbol_scope', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN `symbol_scope` VARCHAR(20) NOT NULL DEFAULT 'all' AFTER `auto_order`" );
        }
        if ( ! in_array( 'watchlist_id', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN `watchlist_id` BIGINT UNSIGNED DEFAULT NULL AFTER `symbol_scope`" );
        }
        if ( ! in_array( 'custom_symbols', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN `custom_symbols` TEXT DEFAULT NULL AFTER `watchlist_id`" );
        }
    }
}
