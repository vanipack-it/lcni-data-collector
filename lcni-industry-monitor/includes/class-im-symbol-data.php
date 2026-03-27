<?php
/**
 * LCNI_IM_Symbol_Data
 * Data layer cho mode = symbol: lấy dữ liệu từ wp_lcni_ohlc.
 * Row = symbol, col = event_time, value = giá trị cột OHLC đã chọn.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_IM_Symbol_Data {

    /** @var wpdb */
    private $wpdb;

    public function __construct( $db = null ) {
        global $wpdb;
        $this->wpdb = $db ?? $wpdb;
    }

    // ── Supported metrics = các cột numeric của lcni_ohlc ────────────────────

    /** @return string[] */
    public function get_supported_metrics() {
        return array_keys( LCNI_IM_Monitor_DB::get_ohlc_numeric_columns() );
    }

    // ── Event times (distinct dates) ────────────────────────────────────────

    /**
     * @param  string[] $symbols  — lọc theo symbol nếu không rỗng
     * @return string[]
     */
    public function get_event_times( string $timeframe, int $limit, string $metric, array $symbols = [] ) {
        $table = $this->wpdb->prefix . 'lcni_ohlc';
        $tf    = sanitize_text_field( $timeframe );
        $limit = max( 1, min( 200, $limit ) );

        if ( ! $this->column_exists( $metric ) ) return [];

        $sql  = "SELECT DISTINCT event_time FROM {$table} WHERE timeframe = %s";
        $args = [ $tf ];

        if ( ! empty( $symbols ) ) {
            $ph   = implode( ',', array_fill( 0, count( $symbols ), '%s' ) );
            $sql .= " AND symbol IN ({$ph})";
            $args = array_merge( $args, array_map( 'strtoupper', $symbols ) );
        }

        $sql   .= ' ORDER BY event_time DESC LIMIT %d';
        $args[] = $limit;

        $rows = (array) $this->wpdb->get_col( $this->wpdb->prepare( $sql, $args ) );
        return $rows;
    }

    public function format_event_time( $raw ) {
        $ts = (int) $raw;
        if ( $ts <= 0 ) return (string) $raw;
        return gmdate( 'd-m-Y', $ts );
    }

    // ── Rows ─────────────────────────────────────────────────────────────────

    /**
     * @param  string[] $event_times
     * @param  string[] $symbols
     * @return array<int,array{industry:string,values:array<int,float|null>}>
     */
    public function get_metric_rows( string $metric, string $timeframe, array $event_times, array $symbols = [] ) {
        if ( empty( $event_times ) ) return [];
        if ( ! $this->column_exists( $metric ) ) return [];

        $table = $this->wpdb->prefix . 'lcni_ohlc';
        $col   = sanitize_key( $metric );
        $ph    = implode( ',', array_fill( 0, count( $event_times ), '%s' ) );

        $sql  = "SELECT symbol, event_time, `{$col}` AS metric_value
                 FROM {$table}
                 WHERE timeframe = %s AND event_time IN ({$ph})";
        $args = array_merge( [ sanitize_text_field( $timeframe ) ], $event_times );

        if ( ! empty( $symbols ) ) {
            $sph  = implode( ',', array_fill( 0, count( $symbols ), '%s' ) );
            $sql .= " AND symbol IN ({$sph})";
            $args = array_merge( $args, array_map( 'strtoupper', $symbols ) );
        }

        $sql .= ' ORDER BY symbol ASC, event_time DESC';

        $rows      = (array) $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
        $time_idx  = array_flip( $event_times );
        $grouped   = [];

        foreach ( $rows as $row ) {
            $sym  = (string) ( $row['symbol'] ?? '' );
            $et   = (string) ( $row['event_time'] ?? '' );
            if ( $sym === '' || ! isset( $time_idx[ $et ] ) ) continue;

            if ( ! isset( $grouped[ $sym ] ) ) {
                $grouped[ $sym ] = array_fill( 0, count( $event_times ), null );
            }

            $v = $row['metric_value'];
            $grouped[ $sym ][ $time_idx[ $et ] ] = ( $v !== null && $v !== '' ) ? (float) $v : null;
        }

        $result = [];
        foreach ( $grouped as $sym => $values ) {
            $result[] = [ 'industry' => $sym, 'values' => $values ];
        }
        return $result;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function column_exists( string $col ) {
        return isset( LCNI_IM_Monitor_DB::get_ohlc_numeric_columns()[ $col ] );
    }

    public function resolve_timeframe( string $metric, string $preferred = '1D' ) {
        return $preferred; // ohlc luôn có 1D
    }
}
