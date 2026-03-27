<?php
/**
 * LCNI Redis Cache Layer
 *
 * Chiến lược cache dựa trên phân tích codebase thực tế:
 *
 *  GROUP              TTL     INVALIDATED BY
 *  ─────────────────────────────────────────────────────────
 *  lcni_ref           3600    upsert_symbol_rows()
 *  lcni_ohlc_latest    300    perform_refresh_ohlc_latest_snapshot()
 *  lcni_market_stats   300    rebuild_market_stats / seed pipeline
 *  lcni_rest_api        60    sau mỗi sync batch (qua REST)
 *  lcni_static        86400   không thay đổi (ICB, marketid, indexname)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_RedisCache {

    // Cache groups — khớp với Redis Object Cache group-based invalidation
    const GRP_REF          = 'lcni_ref';          // symbols, exchange mapping
    const GRP_OHLC_LATEST  = 'lcni_ohlc_latest';  // lcni_ohlc_latest snapshot
    const GRP_MARKET_STATS = 'lcni_market_stats';  // thong_ke_thi_truong, nganh_icb2
    const GRP_REST         = 'lcni_rest_api';       // REST API responses
    const GRP_STATIC       = 'lcni_static';         // icb2, marketid, indexname

    // TTL constants (seconds)
    const TTL_REF          = HOUR_IN_SECONDS;
    const TTL_OHLC_LATEST  = 5 * MINUTE_IN_SECONDS;
    const TTL_MARKET_STATS = 5 * MINUTE_IN_SECONDS;
    const TTL_REST         = MINUTE_IN_SECONDS;
    const TTL_STATIC       = DAY_IN_SECONDS;

    /**
     * Get với fallback tự động.
     * Dùng wp_cache_get (→ Redis nếu connected) với transient fallback.
     */
    public static function get( string $key, string $group ) {
        $found = false;
        $value = wp_cache_get( $key, $group, false, $found );
        if ( $found ) return $value;

        // Transient fallback (khi Redis restart hoặc chưa warm)
        $value = get_transient( self::transient_key( $key, $group ) );
        if ( $value !== false ) {
            // Warm lại Redis từ transient
            $ttl = self::ttl_for_group( $group );
            wp_cache_set( $key, $value, $group, $ttl );
        }
        return $value;
    }

    /**
     * Set vào cả Redis và transient.
     */
    public static function set( string $key, $value, string $group, int $ttl = 0 ): bool {
        $ttl = $ttl > 0 ? $ttl : self::ttl_for_group( $group );
        wp_cache_set( $key, $value, $group, $ttl );
        set_transient( self::transient_key( $key, $group ), $value, $ttl );
        return true;
    }

    /**
     * Remember pattern — cache-aside chuẩn.
     */
    public static function remember( string $key, string $group, callable $cb, int $ttl = 0 ) {
        $cached = self::get( $key, $group );
        if ( $cached !== false ) return $cached;

        $value = $cb();
        if ( $value !== false && $value !== null ) {
            self::set( $key, $value, $group, $ttl );
        }
        return $value;
    }

    /**
     * Xóa 1 key.
     */
    public static function delete( string $key, string $group ): void {
        wp_cache_delete( $key, $group );
        delete_transient( self::transient_key( $key, $group ) );
    }

    // =========================================================================
    // INVALIDATION — gọi từ các write paths trong class-lcni-db.php
    // =========================================================================

    /**
     * Sau perform_refresh_ohlc_latest_snapshot($symbols).
     * Gọi từ: upsert_ohlc_rows() → perform_refresh_ohlc_latest_snapshot()
     */
    public static function invalidate_ohlc_latest( array $symbols = [] ): void {
        if ( empty( $symbols ) ) {
            // Full refresh — flush toàn bộ group
            wp_cache_flush_group( self::GRP_OHLC_LATEST );
            // Flush các REST keys liên quan
            wp_cache_flush_group( self::GRP_REST );
            return;
        }

        // Per-symbol invalidation
        foreach ( $symbols as $sym ) {
            $sym = strtoupper( (string) $sym );
            self::delete( 'ohlc_latest:' . $sym, self::GRP_OHLC_LATEST );
            // Xóa các REST cache liên quan đến symbol này
            self::delete( 'lcni:' . md5( 'stock_detail:' . $sym . ':free' ),    self::GRP_REST );
            self::delete( 'lcni:' . md5( 'stock_detail:' . $sym . ':premium' ), self::GRP_REST );
            self::delete( 'lcni:' . md5( 'stock_signals:' . $sym ),             self::GRP_REST );
            self::delete( 'lcni:' . md5( 'stock_overview:' . $sym ),            self::GRP_REST );
        }

        // Market stats luôn cần refresh sau batch upsert
        wp_cache_flush_group( self::GRP_MARKET_STATS );
    }

    /**
     * Sau upsert_symbol_rows() — symbols/exchange mapping thay đổi.
     */
    public static function invalidate_symbol_ref( array $symbols = [] ): void {
        if ( empty( $symbols ) ) {
            wp_cache_flush_group( self::GRP_REF );
            return;
        }
        foreach ( $symbols as $sym ) {
            self::delete( 'exchange:' . strtoupper( $sym ), self::GRP_REF );
            self::delete( 'sym_info:' . strtoupper( $sym ), self::GRP_REF );
        }
        // all_symbols list cũng thay đổi
        self::delete( 'all_symbols', self::GRP_REF );
    }

    /**
     * Sau seed pipeline / rebuild market stats.
     */
    public static function invalidate_market_stats(): void {
        wp_cache_flush_group( self::GRP_MARKET_STATS );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function ttl_for_group( string $group ): int {
        $map = [
            self::GRP_REF          => self::TTL_REF,
            self::GRP_OHLC_LATEST  => self::TTL_OHLC_LATEST,
            self::GRP_MARKET_STATS => self::TTL_MARKET_STATS,
            self::GRP_REST         => self::TTL_REST,
            self::GRP_STATIC       => self::TTL_STATIC,
        ];
        return $map[ $group ] ?? MINUTE_IN_SECONDS;
    }

    private static function transient_key( string $key, string $group ): string {
        // Transient key max 172 chars — dùng md5 nếu dài
        $raw = $group . '_' . $key;
        return strlen( $raw ) > 160 ? $group . '_' . md5( $key ) : $raw;
    }
}
