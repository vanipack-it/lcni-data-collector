<?php
/**
 * LCNI Cache Flush REST Controller
 *
 * Endpoint: POST /wp-json/lcni/v1/cache/flush
 *
 * Dùng để sync.php (chạy ngoài WP context) trigger cache invalidation
 * sau khi upsert batch thành công.
 *
 * sync.php gọi endpoint này sau mỗi batch OHLC hoặc market stats.
 *
 * Auth: cùng api_key với sync.php (lưu trong wp_options).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_CacheFlushController {

    const ROUTE_NS  = 'lcni/v1';
    const ROUTE     = '/cache/flush';
    const OPTION_KEY = 'lcni_sync_api_key';  // cùng key với sync.php

    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( self::ROUTE_NS, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ $this, 'flush' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'groups'  => [
                    'type'    => 'array',
                    'default' => [],
                    'items'   => [ 'type' => 'string' ],
                ],
                'symbols' => [
                    'type'    => 'array',
                    'default' => [],
                    'items'   => [ 'type' => 'string' ],
                ],
                'mode'    => [
                    'type'    => 'string',
                    'default' => 'ohlc',
                    'enum'    => [ 'ohlc', 'market_stats', 'all', 'ref' ],
                ],
            ],
        ] );

        // GET endpoint để verify connection từ sync.php
        register_rest_route( self::ROUTE_NS, '/cache/ping', [
            'methods'             => 'GET',
            'callback'            => fn() => rest_ensure_response( [ 'status' => 'ok', 'redis' => $this->redis_status() ] ),
            'permission_callback' => [ $this, 'check_auth' ],
        ] );
    }

    /**
     * Auth: Bearer token hoặc api_key trong body.
     */
    public function check_auth( WP_REST_Request $req ): bool {
        $stored_key = get_option( self::OPTION_KEY, '' );
        if ( ! $stored_key ) return false;

        // Bearer token header
        $auth = $req->get_header( 'Authorization' );
        if ( $auth && str_starts_with( $auth, 'Bearer ' ) ) {
            return hash_equals( $stored_key, substr( $auth, 7 ) );
        }

        // api_key trong body JSON
        $api_key = $req->get_param( 'api_key' );
        if ( $api_key ) {
            return hash_equals( $stored_key, (string) $api_key );
        }

        return false;
    }

    /**
     * Flush cache dựa theo mode và symbols.
     */
    public function flush( WP_REST_Request $req ): WP_REST_Response {
        $mode    = $req->get_param( 'mode' );
        $symbols = array_filter( array_map( 'strtoupper', (array) $req->get_param( 'symbols' ) ) );
        $flushed = [];

        switch ( $mode ) {
            case 'ohlc':
                LCNI_RedisCache::invalidate_ohlc_latest( array_values( $symbols ) );
                $flushed[] = 'ohlc_latest';
                $flushed[] = 'rest_api';
                break;

            case 'market_stats':
                LCNI_RedisCache::invalidate_market_stats();
                $flushed[] = 'market_stats';
                break;

            case 'ref':
                LCNI_RedisCache::invalidate_symbol_ref( array_values( $symbols ) );
                $flushed[] = 'ref';
                break;

            case 'all':
            default:
                LCNI_RedisCache::invalidate_ohlc_latest();
                LCNI_RedisCache::invalidate_market_stats();
                if ( ! empty( $symbols ) ) {
                    LCNI_RedisCache::invalidate_symbol_ref( array_values( $symbols ) );
                }
                $flushed = [ 'all' ];
                break;
        }

        return rest_ensure_response( [
            'status'  => 'ok',
            'flushed' => $flushed,
            'symbols' => array_values( $symbols ),
            'mode'    => $mode,
            'time'    => time(),
        ] );
    }

    private function redis_status(): string {
        if ( ! function_exists( 'wp_cache_get' ) ) return 'unavailable';
        $test_key = 'lcni_redis_ping_' . time();
        wp_cache_set( $test_key, 1, 'lcni_ref', 5 );
        $hit = wp_cache_get( $test_key, 'lcni_ref' );
        return $hit === 1 ? 'connected' : 'no_cache';
    }
}
