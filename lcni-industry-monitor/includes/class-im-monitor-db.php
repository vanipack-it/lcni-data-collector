<?php
/**
 * LCNI_IM_Monitor_DB
 * CRUD cho bảng wp_lcni_im_monitors
 *
 * Schema:
 *   id            INT AUTO_INCREMENT PK
 *   name          VARCHAR(120)       — tên hiển thị
 *   mode          ENUM('icb','symbol') — loại cột 1
 *   config        LONGTEXT (JSON)    — toàn bộ cài đặt
 *   created_at    DATETIME
 *   updated_at    DATETIME
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_IM_Monitor_DB {

    const TABLE = 'lcni_im_monitors';
    const VERSION_OPTION = 'lcni_im_monitors_db_version';
    const SCHEMA_VERSION = 1;

    /** @var wpdb */
    /** @var wpdb|null */
    private static $wpdb_instance;

    /** @return wpdb */
    private static function db() {
        global $wpdb;
        return $wpdb;
    }

    public static function table() {
        return self::db()->prefix . self::TABLE;
    }

    // ── Schema ──────────────────────────────────────────────────────────────

    public static function ensure_table() {
        if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION ) return;

        $t   = self::table();
        $col = self::db()->get_charset_collate();

        $sql = "CREATE TABLE {$t} (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(120) NOT NULL DEFAULT '',
            mode        VARCHAR(20)  NOT NULL DEFAULT 'icb',
            config      LONGTEXT     NOT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$col};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
    }

    // ── Read ────────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public static function get_all() {
        $rows = self::db()->get_results(
            'SELECT id, name, mode, created_at, updated_at FROM ' . self::table() . ' ORDER BY id ASC',
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return array<string,mixed>|null */
    public static function find( int $id ) {
        $row = self::db()->get_row(
            self::db()->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
            ARRAY_A
        );
        if ( ! $row ) return null;
        $row['config'] = self::decode_config( $row['config'] ?? '' );
        return $row;
    }

    // ── Write ───────────────────────────────────────────────────────────────

    public static function insert( string $name, string $mode, array $config ) {
        self::db()->insert( self::table(), [
            'name'   => sanitize_text_field( $name ),
            'mode'   => in_array( $mode, ['icb','symbol'], true ) ? $mode : 'icb',
            'config' => wp_json_encode( $config ),
        ], [ '%s', '%s', '%s' ] );
        return (int) self::db()->insert_id;
    }

    public static function update( int $id, string $name, string $mode, array $config ) {
        $result = self::db()->update( self::table(), [
            'name'   => sanitize_text_field( $name ),
            'mode'   => in_array( $mode, ['icb','symbol'], true ) ? $mode : 'icb',
            'config' => wp_json_encode( $config ),
        ], [ 'id' => $id ], [ '%s', '%s', '%s' ], [ '%d' ] );
        return $result !== false;
    }

    public static function delete( int $id ) {
        return (bool) self::db()->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function decode_config( string $json ) {
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Lấy danh sách cột numeric từ wp_lcni_ohlc để dùng cho mode=symbol.
     * @return array<string,string>  [ column_key => column_key ]
     */
    public static function get_ohlc_numeric_columns() {
        global $wpdb;
        $table   = $wpdb->prefix . 'lcni_ohlc';
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        if ( ! is_array( $columns ) ) return [];

        $excluded = [ 'id', 'symbol', 'timeframe', 'event_time', 'created_at', 'updated_at',
                      'one_candle', 'two_candle_pattern', 'three_candle_pattern',
                      'symbol_type', 'indicators_ready' ];

        $numeric_pattern = '/(int|decimal|numeric|float|double|real|bigint|smallint|tinyint)/i';
        $result = [];
        foreach ( $columns as $col ) {
            $name = $col['Field'] ?? '';
            $type = $col['Type']  ?? '';
            if ( $name === '' ) continue;
            if ( in_array( $name, $excluded, true ) ) continue;
            if ( preg_match( '/varchar|text|char|enum|tinyint/i', $type ) && ! preg_match( '/tinyint.*unsigned/i', $type ) ) continue;
            if ( ! preg_match( $numeric_pattern, $type ) ) continue;
            $result[ $name ] = $name;
        }
        return $result;
    }

    /**
     * Default config cho monitor mới.
     * @return array<string,mixed>
     */
    public static function default_config() {
        $global = LCNI_Industry_Settings::get_settings();
        return [
            'enabled_metrics'       => $global['enabled_metrics'] ?? [],
            'ohlc_columns'          => [],            // symbol mode: các cột được bật
            'event_time_col_width'  => $global['event_time_col_width']  ?? 140,
            'dropdown_height'       => $global['dropdown_height']       ?? 36,
            'dropdown_width'        => $global['dropdown_width']        ?? 280,
            'dropdown_border_color' => $global['dropdown_border_color'] ?? '#d0d0d0',
            'dropdown_border_width' => $global['dropdown_border_width'] ?? 1,
            'row_hover_enabled'     => $global['row_hover_enabled']     ?? 1,
            'industry_filter_url'   => $global['industry_filter_url']   ?? home_url('/'),
            'symbol_filter_url'     => home_url('/'),
            'compact_full_table_url'=> $global['compact_full_table_url'] ?? home_url('/'),
            'default_session_limit' => $global['default_session_limit'] ?? 30,
            'cell_rules'            => $global['cell_rules']            ?? [],
            'row_gradient_rules'    => $global['row_gradient_rules']    ?? [],
        ];
    }
}
