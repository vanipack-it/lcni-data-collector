<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MarketDashboardRestController
 *
 * Route: GET /wp-json/lcni/v1/market-dashboard/snapshot
 * Params:
 *   timeframe  = '1D' | '1W' | '1M'  (default: '1D')
 *   event_time = integer              (default: 0 = latest)
 *   refresh    = 1                    (bỏ qua cache, tính lại)
 */
class LCNI_MarketDashboardRestController {

    const REST_NAMESPACE = 'lcni/v1';
    const REST_BASE      = 'market-dashboard';

    /** @var LCNI_MarketDashboardRepository */
    private $repo;

    public function __construct() {
        $this->repo = new LCNI_MarketDashboardRepository();
    }

    public function register_routes(): void {
        register_rest_route( self::REST_NAMESPACE, '/' . self::REST_BASE . '/snapshot', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_snapshot' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'timeframe'  => [
                    'default'           => '1D',
                    'sanitize_callback' => fn( $v ) => strtoupper( sanitize_text_field( (string) $v ) ),
                    'validate_callback' => fn( $v ) => in_array( strtoupper( $v ), ['1D','1W','1M'], true ),
                ],
                'event_time' => [
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ],
                'refresh' => [
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/' . self::REST_BASE . '/available-dates', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_available_dates' ],
            'permission_callback' => [ $this, 'check_permission' ],
            'args'                => [
                'timeframe' => [
                    'default'           => '1D',
                    'sanitize_callback' => fn( $v ) => strtoupper( sanitize_text_field( (string) $v ) ),
                ],
            ],
        ] );
    }

    public function check_permission( WP_REST_Request $req ): bool {
        // Đọc công khai nếu plugin member không tồn tại
        // Nếu có plugin member → kiểm tra is_user_logged_in()
        if ( function_exists( 'lcni_user_has_permission' ) ) {
            return lcni_user_has_permission( 'market_dashboard', 'can_view' );
        }
        return true;
    }

    public function get_snapshot( WP_REST_Request $req ): WP_REST_Response {
        $tf         = $req->get_param( 'timeframe' );
        $et         = (int) $req->get_param( 'event_time' );
        $use_cache  = empty( $req->get_param( 'refresh' ) );

        $snapshot = $this->repo->get_snapshot( $tf, $et, $use_cache );

        if ( empty( $snapshot ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Chưa có dữ liệu thống kê thị trường. Vui lòng chạy lại quá trình tính toán.',
                'data'    => null,
            ], 200 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $snapshot,
        ], 200 );
    }

    public function get_available_dates( WP_REST_Request $req ): WP_REST_Response {
        $tf    = $req->get_param( 'timeframe' );
        $dates = $this->repo->get_available_event_times( $tf, 90 );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $dates,
        ], 200 );
    }
}
