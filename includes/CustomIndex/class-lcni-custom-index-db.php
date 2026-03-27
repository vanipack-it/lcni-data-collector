<?php
/**
 * Custom Index DB
 * Quản lý schema và migration cho 2 bảng:
 *   lcni_custom_index        — định nghĩa chỉ số (tên, bộ lọc symbol)
 *   lcni_custom_index_ohlc   — nến OHLC tính theo phương pháp value-weighted
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_Custom_Index_DB {

    // ── Tên bảng ─────────────────────────────────────────────────────────────

    public static function index_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'lcni_custom_index';
    }

    public static function ohlc_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'lcni_custom_index_ohlc';
    }

    // ── Install / ensure ──────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $idx  = self::index_table();
        $ohlc = self::ohlc_table();

        // Bảng định nghĩa chỉ số
        dbDelta( "CREATE TABLE {$idx} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name          VARCHAR(100)   NOT NULL,
            description   TEXT           DEFAULT NULL,
            exchange      VARCHAR(20)    DEFAULT NULL COMMENT 'HOSE|HNX|UPCOM|NULL=all',
            id_icb2       SMALLINT UNSIGNED DEFAULT NULL COMMENT 'NULL=all ngành',
            symbol_scope  VARCHAR(20)    NOT NULL DEFAULT 'all'
                          COMMENT 'all|watchlist|custom',
            scope_watchlist_id BIGINT UNSIGNED DEFAULT NULL,
            scope_custom_list  TEXT           DEFAULT NULL,
            base_event_time   BIGINT UNSIGNED DEFAULT NULL
                          COMMENT 'event_time phiên gốc (index=100)',
            base_value        DECIMAL(30,6)  DEFAULT NULL
                          COMMENT 'tổng value_traded * close_price phiên gốc',
            is_active     TINYINT(1)     NOT NULL DEFAULT 1,
            created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active (is_active)
        ) $charset;" );

        // Bảng OHLC của chỉ số — lưu nến tính sẵn
        dbDelta( "CREATE TABLE {$ohlc} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            index_id      BIGINT UNSIGNED NOT NULL,
            timeframe     VARCHAR(10)    NOT NULL,
            event_time    BIGINT UNSIGNED NOT NULL,
            open_value    DECIMAL(20,4)  NOT NULL DEFAULT 0
                          COMMENT 'Σ(open  × value_traded) / Σ value_traded × 100 / base',
            high_value    DECIMAL(20,4)  NOT NULL DEFAULT 0,
            low_value     DECIMAL(20,4)  NOT NULL DEFAULT 0,
            close_value   DECIMAL(20,4)  NOT NULL DEFAULT 0,
            total_value_traded DECIMAL(30,2) NOT NULL DEFAULT 0,
            so_ma         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            so_tang       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            so_giam       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_idx_tf_time (index_id, timeframe, event_time),
            KEY idx_index_tf (index_id, timeframe),
            KEY idx_event_time (event_time)
        ) $charset;" );
    }

    public static function ensure(): void {
        global $wpdb;
        try {
            $t = self::index_table();
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
                self::install();
            }
        } catch ( \Throwable $e ) {
            error_log( '[LCNI CustomIndex] DB ensure error: ' . $e->getMessage() );
        }
    }
}
