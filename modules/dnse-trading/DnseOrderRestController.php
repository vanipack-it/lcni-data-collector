<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DnseOrderRestController — Giai đoạn 2
 *
 * REST endpoints:
 *   GET  /wp-json/lcni/v1/dnse/signals           — Open signals + order suggestions
 *   GET  /wp-json/lcni/v1/dnse/loan-packages      — Gói vay
 *   POST /wp-json/lcni/v1/dnse/order              — Đặt lệnh (cần confirm)
 *   POST /wp-json/lcni/v1/dnse/order/{id}/cancel  — Hủy lệnh
 */
class LCNI_DnseOrderRestController {

    const NS = 'lcni/v1';

    /** @var LCNI_DnseOrderService */
    private $order_service;

    /** @var LCNI_DnseTradingService */
    private $trading_service;

    public function __construct(
        LCNI_DnseOrderService   $order_service,
        LCNI_DnseTradingService $trading_service
    ) {
        $this->order_service   = $order_service;
        $this->trading_service = $trading_service;
    }

    public function register_routes(): void {
        $ns = self::NS;

        register_rest_route( $ns, '/dnse/signals', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_signals' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        register_rest_route( $ns, '/dnse/loan-packages', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_loan_packages' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args' => [
                'account_no'   => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'account_type' => [ 'default'  => 'spot', 'sanitize_callback' => 'sanitize_key' ],
            ],
        ] );

        register_rest_route( $ns, '/dnse/order', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'place_order' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args' => [
                'account_no'      => [ 'required' => true ],
                'symbol'          => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'side'            => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
                'order_type'      => [ 'default'  => 'LO', 'sanitize_callback' => 'sanitize_text_field' ],
                'price'           => [ 'required' => true, 'sanitize_callback' => 'floatval' ],
                'quantity'        => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'loan_package_id' => [ 'default'  => 0,    'sanitize_callback' => 'absint' ],
                'account_type'    => [ 'default'  => 'spot', 'sanitize_callback' => 'sanitize_key' ],
                'signal_id'       => [ 'default'  => 0,    'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( $ns, '/dnse/buying-power', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_buying_power' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args' => [
                'account_no'   => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'account_type' => [ 'default'  => 'spot', 'sanitize_callback' => 'sanitize_key' ],
                'symbol'       => [ 'default'  => '',    'sanitize_callback' => 'sanitize_text_field' ],
                'price'        => [ 'default'  => 0,     'sanitize_callback' => 'floatval' ],
            ],
        ] );

        register_rest_route( $ns, '/dnse/order/(?P<order_id>[^/]+)/cancel', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'cancel_order' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args' => [
                'account_no'   => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'account_type' => [ 'default'  => 'spot', 'sanitize_callback' => 'sanitize_key' ],
            ],
        ] );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function get_signals( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        $signals = $this->order_service->get_open_signals_for_user( $user_id );
        $status  = $this->trading_service->get_connection_status( $user_id );

        return $this->ok( [
            'signals'     => $signals,
            'has_trading' => $status['has_trading'] ?? false,
            'connected'   => $status['connected']   ?? false,
            'accounts'    => $status['sub_accounts'] ?? [],
        ] );
    }

    public function get_loan_packages( WP_REST_Request $req ): WP_REST_Response {
        $result = $this->order_service->get_loan_packages(
            get_current_user_id(),
            $req->get_param( 'account_no' ),
            $req->get_param( 'account_type' )
        );

        if ( is_wp_error( $result ) ) return $this->error( $result );

        return $this->ok( [ 'packages' => $result ] );
    }

    public function place_order( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();

        $result = $this->order_service->place_order( $user_id, [
            'account_no'      => $req->get_param( 'account_no' ),
            'symbol'          => $req->get_param( 'symbol' ),
            'side'            => $req->get_param( 'side' ),
            'order_type'      => $req->get_param( 'order_type' ),
            'price'           => $req->get_param( 'price' ),
            'quantity'        => $req->get_param( 'quantity' ),
            'loan_package_id' => $req->get_param( 'loan_package_id' ),
            'account_type'    => $req->get_param( 'account_type' ),
        ] );

        if ( is_wp_error( $result ) ) return $this->error( $result );

        return $this->ok( $result );
    }

    public function cancel_order( WP_REST_Request $req ): WP_REST_Response {
        $result = $this->order_service->cancel_order(
            get_current_user_id(),
            $req->get_param( 'order_id' ),
            $req->get_param( 'account_no' ),
            $req->get_param( 'account_type' )
        );

        if ( is_wp_error( $result ) ) return $this->error( $result );

        return $this->ok( [ 'message' => 'Đã hủy lệnh thành công.' ] );
    }

    public function get_buying_power( WP_REST_Request $req ): WP_REST_Response {
        $result = $this->order_service->get_buying_power(
            get_current_user_id(),
            $req->get_param( 'account_no' ),
            $req->get_param( 'account_type' ),
            (string) $req->get_param( 'symbol' ),
            (float)  $req->get_param( 'price' )
        );

        if ( is_wp_error( $result ) ) return $this->error( $result );

        return $this->ok( $result );
    }

    // ── Permission / Helpers ─────────────────────────────────────────────────

    public function require_login(): bool {
        return is_user_logged_in();
    }

    private function ok( array $data ): WP_REST_Response {
        return new WP_REST_Response( array_merge( [ 'success' => true ], $data ), 200 );
    }

    private function error( WP_Error $err ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => false,
            'code'    => $err->get_error_code(),
            'message' => $err->get_error_message(),
        ], 400 );
    }
}
