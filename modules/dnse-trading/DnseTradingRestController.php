<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DnseTradingRestController
 *
 * REST endpoints để frontend JS gọi:
 *
 *   POST /wp-json/lcni/v1/dnse/connect        — Đăng nhập DNSE
 *   POST /wp-json/lcni/v1/dnse/request-otp    — Gửi Email OTP
 *   POST /wp-json/lcni/v1/dnse/verify-otp     — Xác thực OTP
 *   POST /wp-json/lcni/v1/dnse/disconnect     — Ngắt kết nối
 *   GET  /wp-json/lcni/v1/dnse/status         — Trạng thái kết nối
 *   GET  /wp-json/lcni/v1/dnse/dashboard      — Đọc cache dashboard
 *   POST /wp-json/lcni/v1/dnse/sync           — Trigger sync từ DNSE
 */
class LCNI_DnseTradingRestController {

    const NS = 'lcni/v1';

    /** @var LCNI_DnseTradingService */
    private $service;

    public function __construct( LCNI_DnseTradingService $service ) {
        $this->service = $service;
    }

    public function register_routes(): void {
        $ns = self::NS;

        register_rest_route( $ns, '/dnse/connect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'connect' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args'                => [
                'username' => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'password' => [ 'required' => true ],
            ],
        ] );

        register_rest_route( $ns, '/dnse/request-otp', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'request_otp' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        register_rest_route( $ns, '/dnse/verify-otp', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'verify_otp' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args'                => [
                'otp'      => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'otp_type' => [ 'default'  => 'smart', 'sanitize_callback' => 'sanitize_key' ],
            ],
        ] );

        register_rest_route( $ns, '/dnse/disconnect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'disconnect' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        register_rest_route( $ns, '/dnse/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        register_rest_route( $ns, '/dnse/dashboard', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_dashboard' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        register_rest_route( $ns, '/dnse/sync', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'sync' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function connect( WP_REST_Request $req ): WP_REST_Response {
        $user_id  = get_current_user_id();
        $username = $req->get_param( 'username' );
        $password = $req->get_param( 'password' );

        $result = $this->service->connect( $user_id, $username, $password );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result );
        }

        return $this->ok( [
            'message'      => 'Kết nối DNSE thành công. Vui lòng xác thực OTP để giao dịch.',
            'sub_accounts' => $result['sub_accounts'],
        ] );
    }

    public function request_otp( WP_REST_Request $req ): WP_REST_Response {
        $result = $this->service->request_email_otp( get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result );
        }

        return $this->ok( [ 'message' => 'OTP đã được gửi về email đăng ký của bạn.' ] );
    }

    public function verify_otp( WP_REST_Request $req ): WP_REST_Response {
        $user_id  = get_current_user_id();
        $otp      = $req->get_param( 'otp' );
        $otp_type = $req->get_param( 'otp_type' ) ?: 'smart';

        if ( ! in_array( $otp_type, [ 'smart', 'email' ], true ) ) {
            $otp_type = 'smart';
        }

        $result = $this->service->authenticate_otp( $user_id, $otp, $otp_type );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result );
        }

        return $this->ok( [ 'message' => 'Xác thực OTP thành công. Trading token có hiệu lực 8 giờ.' ] );
    }

    public function disconnect( WP_REST_Request $req ): WP_REST_Response {
        $this->service->disconnect( get_current_user_id() );
        return $this->ok( [ 'message' => 'Đã ngắt kết nối DNSE.' ] );
    }

    public function get_status( WP_REST_Request $req ): WP_REST_Response {
        $status = $this->service->get_connection_status( get_current_user_id() );
        // Không trả về token thực sự ra frontend
        unset( $status['jwt_token'], $status['trading_token'] );
        return $this->ok( $status );
    }

    public function get_dashboard( WP_REST_Request $req ): WP_REST_Response {
        $data = $this->service->get_dashboard_data( get_current_user_id() );
        return $this->ok( $data );
    }

    public function sync( WP_REST_Request $req ): WP_REST_Response {
        $result = $this->service->sync_all( get_current_user_id() );

        return $this->ok( [
            'message'          => "Đồng bộ xong {$result['synced_accounts']} tiểu khoản.",
            'synced_accounts'  => $result['synced_accounts'],
            'errors'           => $result['errors'],
        ] );
    }

    // ── Permission ────────────────────────────────────────────────────────────

    public function require_login(): bool {
        return is_user_logged_in();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function ok( array $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( array_merge( [ 'success' => true ], $data ), $status );
    }

    private function error( WP_Error $err ): WP_REST_Response {
        return new WP_REST_Response( [
            'success' => false,
            'code'    => $err->get_error_code(),
            'message' => $err->get_error_message(),
        ], 400 );
    }
}
