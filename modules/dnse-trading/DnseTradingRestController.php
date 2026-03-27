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

    /** @var LCNI_Dnse_Gmail_OAuth_Service|null */
    private $gmail;

    /** @var LCNI_DnseTradingRepository */
    private $repo;

    public function __construct(
        LCNI_DnseTradingService        $service,
        ?LCNI_Dnse_Gmail_OAuth_Service $gmail = null,
        ?LCNI_DnseTradingRepository    $repo  = null
    ) {
        $this->service = $service;
        $this->gmail   = $gmail;
        $this->repo    = $repo;
    }

    public function register_routes(): void {
        $ns = self::NS;

        register_rest_route( $ns, '/dnse/connect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'connect' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args'                => [
                'username'    => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'password'    => [ 'required' => true ],
                'permissions' => [ 'default'  => [],    'sanitize_callback' => function( $v ) {
                    return is_array( $v ) ? array_map( 'sanitize_key', $v ) : [];
                } ],
            ],
        ] );

        register_rest_route( $ns, '/dnse/reconnect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'reconnect' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        register_rest_route( $ns, '/dnse/save-permissions', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'save_permissions' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args'                => [
                'permissions' => [ 'required' => true, 'sanitize_callback' => function( $v ) {
                    return is_array( $v ) ? array_map( 'sanitize_key', $v ) : [];
                } ],
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

        // ── Gmail Auto-Renew ──────────────────────────────────────────────────
        register_rest_route( $ns, '/dnse/gmail-auth-url', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_gmail_auth_url' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        register_rest_route( $ns, '/dnse/gmail-disconnect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'gmail_disconnect' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        register_rest_route( $ns, '/dnse/gmail-status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_gmail_status' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        // Poll Gmail inbox để lấy OTP sau khi /connect đã gửi email OTP
        register_rest_route( $ns, '/dnse/gmail-otp-poll', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'gmail_otp_poll' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function connect( WP_REST_Request $req ): WP_REST_Response {
        $user_id     = get_current_user_id();
        $username    = $req->get_param( 'username' );
        $password    = $req->get_param( 'password' );
        $permissions = (array) ( $req->get_param( 'permissions' ) ?: [] );

        $result = $this->service->connect( $user_id, $username, $password, $permissions );

        if ( is_wp_error( $result ) ) {
            return $this->error( $result );
        }

        // Nếu đã kết nối Gmail → gửi email OTP ngay và trả về pending.
        // JS sẽ poll /gmail-otp-poll để lấy kết quả (tránh sleep dài trong request này).
        if ( $this->gmail && $this->gmail->is_connected( $user_id ) ) {
            $jwt = $this->service->get_jwt_for_auto_otp( $user_id );

            if ( ! is_wp_error( $jwt ) ) {
                $sent = $this->gmail->send_email_otp( $user_id, $jwt );

                if ( ! is_wp_error( $sent ) ) {
                    // Lưu thời điểm gửi để poll biết khoảng thời gian tìm email
                    set_transient( 'lcni_dnse_otp_sent_' . $user_id, time(), 5 * MINUTE_IN_SECONDS );

                    return $this->ok( [
                        'message'          => 'Kết nối DNSE thành công. Đang xác thực OTP tự động qua Gmail...',
                        'sub_accounts'     => $result['sub_accounts'],
                        'auto_otp_pending' => true,
                    ] );
                }
            }

            // Gửi OTP thất bại → fallback nhập thủ công
            return $this->ok( [
                'message'      => 'Kết nối DNSE thành công. Vui lòng xác thực OTP để giao dịch.',
                'sub_accounts' => $result['sub_accounts'],
                'auto_otp'     => false,
            ] );
        }

        return $this->ok( [
            'message'      => 'Kết nối DNSE thành công. Vui lòng xác thực OTP để giao dịch.',
            'sub_accounts' => $result['sub_accounts'],
            'auto_otp'     => false,
        ] );
    }

    /**
     * GET /dnse/gmail-otp-poll
     * JS gọi mỗi 5s sau khi /connect trả về auto_otp_pending = true.
     * PHP đọc Gmail tìm OTP → verify → trả { done, success, message }.
     */
    public function gmail_otp_poll( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();

        if ( ! $this->gmail || ! $this->gmail->is_connected( $user_id ) ) {
            return $this->ok( [ 'done' => true, 'success' => false, 'message' => 'Gmail chưa kết nối.' ] );
        }

        // Lấy access token Gmail
        $access_token = $this->gmail->get_access_token( $user_id );
        if ( is_wp_error( $access_token ) ) {
            return $this->ok( [ 'done' => true, 'success' => false, 'message' => $access_token->get_error_message() ] );
        }

        // Đọc timestamp lúc gửi OTP — chỉ lấy email đến SAU thời điểm đó (tránh OTP cũ)
        $sent_at = (int) get_transient( 'lcni_dnse_otp_sent_' . $user_id );

        // Thử đọc OTP từ Gmail — nếu chưa có email thì trả done=false để JS poll tiếp
        $otp = $this->gmail->fetch_otp_from_gmail( $access_token, $sent_at );
        if ( is_wp_error( $otp ) ) {
            // Email chưa đến → JS poll tiếp
            return $this->ok( [ 'done' => false, 'success' => false, 'message' => $otp->get_error_message() ] );
        }

        // Log OTP và thời gian để debug (xoá sau khi ổn định)
        $elapsed = $sent_at > 0 ? ( time() - $sent_at ) : 0;
        error_log( "[LCNI DNSE] Poll OTP: otp={$otp} sent_at={$sent_at} elapsed={$elapsed}s user={$user_id}" );

        // Có OTP → verify với DNSE
        $jwt = $this->service->get_jwt_for_auto_otp( $user_id );
        if ( is_wp_error( $jwt ) ) {
            return $this->ok( [ 'done' => true, 'success' => false, 'message' => $jwt->get_error_message() ] );
        }

        $token_result = $this->gmail->verify_email_otp( $user_id, $jwt, $otp );
        if ( is_wp_error( $token_result ) ) {
            return $this->ok( [ 'done' => true, 'success' => false, 'message' => $token_result->get_error_message() ] );
        }

        delete_transient( 'lcni_dnse_otp_sent_' . $user_id );
        return $this->ok( [
            'done'    => true,
            'success' => true,
            'message' => 'Trading token đã được xác thực tự động qua Gmail.',
        ] );
    }

    /**
     * POST /dnse/reconnect
     * Re-login bằng credentials đã lưu + gửi Email OTP qua Gmail nếu đã kết nối.
     * Dùng khi trading token hết hạn và user muốn xác thực lại từ UI.
     */
    public function reconnect( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();

        // Lấy lại jwt bằng auto re-login
        $jwt = $this->service->get_jwt_for_auto_otp( $user_id );
        if ( is_wp_error( $jwt ) ) {
            return $this->error( $jwt );
        }

        // Nếu Gmail đã kết nối → gửi email OTP và poll
        if ( $this->gmail && $this->gmail->is_connected( $user_id ) ) {
            $sent = $this->gmail->send_email_otp( $user_id, $jwt );
            if ( ! is_wp_error( $sent ) ) {
                set_transient( 'lcni_dnse_otp_sent_' . $user_id, time(), 5 * MINUTE_IN_SECONDS );
                return $this->ok( [
                    'message'          => 'Đang xác thực OTP tự động qua Gmail...',
                    'auto_otp_pending' => true,
                ] );
            }
            // send_email_otp thất bại (DNSE 500) → fallback Smart OTP thủ công
            error_log( '[LCNI DNSE] reconnect: send_email_otp failed for user ' . $user_id . ': ' . $sent->get_error_message() );
        }

        // Fallback: yêu cầu user nhập Smart OTP thủ công
        return $this->ok( [
            'message'    => 'DNSE không gửi được Email OTP. Vui lòng xác thực bằng Smart OTP (app EntradeX).',
            'manual_otp' => true,
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
        $user_id = get_current_user_id();
        $status  = $this->service->get_connection_status( $user_id );
        unset( $status['jwt_token'], $status['trading_token'] );
        // permissions đã được get_connection_status() trả về — không cần gọi thêm

        // Thêm trạng thái Gmail auto-renew
        if ( $this->gmail ) {
            $status['gmail_connected']    = $this->gmail->is_connected( $user_id );
            $status['gmail_email']        = $this->gmail->get_connected_email( $user_id );
            $status['gmail_configured']   = LCNI_Dnse_Gmail_OAuth_Service::is_configured();
        } else {
            $status['gmail_connected']  = false;
            $status['gmail_configured'] = false;
        }

        // Báo JS biết đang trong quá trình poll Gmail OTP (transient còn tồn tại)
        $status['auto_otp_pending'] = (bool) get_transient( 'lcni_dnse_otp_sent_' . $user_id );

        return $this->ok( $status );
    }

    /**
     * POST /dnse/save-permissions
     * User thay đổi permissions sau khi đã connected.
     * Nếu bật perm_auto_renew nhưng password chưa lưu → yêu cầu nhập lại password.
     */
    public function save_permissions( WP_REST_Request $req ): WP_REST_Response {
        $user_id     = get_current_user_id();
        $permissions = (array) $req->get_param( 'permissions' );

        $valid = \LCNI_DnseTradingRepository::VALID_PERMISSIONS;
        $clean = array_values( array_intersect( $permissions, $valid ) );

        // Nếu bật perm_auto_renew nhưng chưa có password → báo JS cần nhập lại password
        $needs_password = false;
        if ( in_array( 'perm_auto_renew', $clean, true ) ) {
            $saved_pw = $this->repo->get_password( $user_id );
            if ( $saved_pw === '' ) {
                $needs_password = true;
            }
        } else {
            // Tắt auto_renew → xóa password đã lưu (bảo mật)
            $this->repo->delete_password( $user_id );
        }

        $this->repo->save_permissions( $user_id, $clean );

        return $this->ok( [
            'permissions'    => $clean,
            'needs_password' => $needs_password,
            'message'        => 'Đã lưu cài đặt.',
        ] );
    }

    // ── Gmail handlers ────────────────────────────────────────────────────────

    public function get_gmail_auth_url( WP_REST_Request $req ): WP_REST_Response {
        if ( ! $this->gmail ) {
            return $this->error( new WP_Error( 'gmail_disabled', 'Gmail service chưa khởi tạo.' ) );
        }
        if ( ! LCNI_Dnse_Gmail_OAuth_Service::is_configured() ) {
            return $this->error( new WP_Error(
                'gmail_not_configured',
                'Chưa cấu hình Gmail OAuth Client ID/Secret. Vui lòng liên hệ Admin.'
            ) );
        }
        $url = $this->gmail->build_auth_url( get_current_user_id() );
        return $this->ok( [ 'url' => $url ] );
    }

    public function gmail_disconnect( WP_REST_Request $req ): WP_REST_Response {
        if ( $this->gmail ) {
            $this->gmail->disconnect( get_current_user_id() );
        }
        return $this->ok( [ 'message' => 'Đã ngắt kết nối Gmail Auto-Renew.' ] );
    }

    public function get_gmail_status( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        if ( ! $this->gmail ) {
            return $this->ok( [ 'connected' => false, 'configured' => false ] );
        }
        return $this->ok( [
            'connected'  => $this->gmail->is_connected( $user_id ),
            'email'      => $this->gmail->get_connected_email( $user_id ),
            'configured' => LCNI_Dnse_Gmail_OAuth_Service::is_configured(),
        ] );
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
