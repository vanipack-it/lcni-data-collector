<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LCNI DNSE Login Handler
 *
 * Cho phép user đăng nhập vào WordPress bằng tài khoản DNSE (EntradeX).
 *
 * Flow:
 *  1. User nhập username + password DNSE vào form login
 *  2. AJAX → handler gọi DNSE API login → nhận JWT
 *  3. Nếu JWT hợp lệ → tìm WP user có dnse_account_no khớp
 *     - Có → đăng nhập luôn
 *     - Không có → chỉ đăng nhập nếu user đang logged-in (lần liên kết đầu tiên)
 *       hoặc nếu settings cho phép tự tạo user mới
 *  4. wp_set_auth_cookie → redirect
 *
 * Bảo mật:
 *  - Password DNSE KHÔNG được lưu lại trong luồng này
 *  - Mỗi lần login gọi DNSE API 1 lần — không cache JWT từ luồng login
 *  - Rate limit: tối đa 5 lần thất bại / 15 phút / IP (dùng transient)
 */
class LCNI_Dnse_Login_Handler {

    const AJAX_ACTION    = 'lcni_dnse_login';
    const NONCE_ACTION   = 'lcni_dnse_login_nonce';
    const RATE_LIMIT_MAX = 5;
    const RATE_LIMIT_TTL = 15 * MINUTE_IN_SECONDS;

    public function __construct() {
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ $this, 'handle' ] );
        add_action( 'wp_ajax_' . self::AJAX_ACTION,        [ $this, 'handle' ] );
    }

    // =========================================================================
    // AJAX handler
    // =========================================================================

    public function handle(): void {
        // 1. Verify nonce
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_send_json_error( 'Phiên đăng nhập hết hạn, vui lòng thử lại.' );
        }

        // 2. Rate limit theo IP
        $ip      = $this->get_client_ip();
        $rl_key  = 'lcni_dnse_login_fail_' . md5( $ip );
        $fails   = (int) get_transient( $rl_key );
        if ( $fails >= self::RATE_LIMIT_MAX ) {
            wp_send_json_error( 'Quá nhiều lần thử thất bại. Vui lòng thử lại sau 15 phút.' );
        }

        // 3. Lấy credentials
        $username = sanitize_text_field( wp_unslash( $_POST['dnse_username'] ?? '' ) );
        $password = (string) wp_unslash( $_POST['dnse_password'] ?? '' );

        if ( $username === '' || $password === '' ) {
            wp_send_json_error( 'Vui lòng nhập đầy đủ tài khoản và mật khẩu DNSE.' );
        }

        // 4. Gọi DNSE API login
        $api    = new LCNI_DnseTradingApiClient();
        $result = $api->login( $username, $password );

        if ( is_wp_error( $result ) ) {
            // Tăng fail counter
            set_transient( $rl_key, $fails + 1, self::RATE_LIMIT_TTL );
            wp_send_json_error( 'Tài khoản hoặc mật khẩu DNSE không đúng.' );
        }

        // Reset fail counter sau khi login thành công
        delete_transient( $rl_key );

        // 5. Tìm WP user đã liên kết tài khoản DNSE này
        $wp_user = $this->find_linked_wp_user( $username );

        if ( ! $wp_user ) {
            // Không tìm thấy user liên kết → không cho đăng nhập
            // (tránh tạo account mới tự động bằng DNSE credentials)
            wp_send_json_error(
                'Tài khoản DNSE ' . esc_html( $username ) . ' chưa được liên kết với tài khoản nào trên hệ thống. '
                . 'Vui lòng đăng nhập bằng tài khoản website trước, sau đó liên kết DNSE trong phần Kết nối.'
            );
        }

        // 6. Đăng nhập WP
        wp_set_current_user( $wp_user->ID );
        wp_set_auth_cookie( $wp_user->ID, true );
        do_action( 'wp_login', $wp_user->user_login, $wp_user );

        // 7. Redirect
        $from_post = isset( $_POST['redirect_to'] )
            ? wp_validate_redirect( (string) wp_unslash( $_POST['redirect_to'] ), '' )
            : '';
        $settings  = get_option( 'lcni_member_login_settings', [] );
        $from_opt  = ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : '';
        $redirect  = $from_post !== '' ? $from_post : ( $from_opt !== '' ? $from_opt : home_url( '/' ) );

        wp_send_json_success( [ 'redirect' => $redirect ] );
    }

    // =========================================================================
    // Tìm WP user đã liên kết tài khoản DNSE
    // =========================================================================

    /**
     * Tìm WP user có dnse_account_no = $account_no trong bảng credentials.
     * account_no là số tài khoản (vd: 064C958993), không phải username/phone.
     *
     * Vì DNSE login trả về JWT (không có account_no trực tiếp),
     * ta match theo dnse_account_no đã lưu khi user kết nối DNSE trước đó.
     * Username input có thể là số điện thoại hoặc account_no → thử cả hai.
     */
    private function find_linked_wp_user( string $input ): ?\WP_User {
        global $wpdb;

        $tbl = $wpdb->prefix . 'lcni_dnse_credentials';

        // Tìm theo dnse_account_no (khớp chính xác, case-insensitive)
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT user_id FROM `{$tbl}`
                 WHERE UPPER(dnse_account_no) = UPPER(%s)
                 LIMIT 1",
                $input
            ),
            ARRAY_A
        );

        if ( $row && ! empty( $row['user_id'] ) ) {
            $user = get_user_by( 'id', (int) $row['user_id'] );
            return $user ?: null;
        }

        // Fallback: tìm theo user_meta lcni_dnse_account_no (nếu lưu ở meta)
        $users = get_users( [
            'meta_key'   => 'lcni_dnse_account_no',
            'meta_value' => strtoupper( $input ),
            'number'     => 1,
        ] );

        return ! empty( $users ) ? $users[0] : null;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function get_client_ip(): string {
        $keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $keys as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                $ip = trim( explode( ',', (string) $_SERVER[ $k ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return 'unknown';
    }

    // =========================================================================
    // Static helpers cho render form và enqueue script
    // =========================================================================

    /**
     * Kiểm tra tính năng DNSE login có được bật không.
     * Admin có thể tắt trong settings.
     */
    public static function is_enabled(): bool {
        $settings = get_option( 'lcni_member_login_settings', [] );
        return ! empty( $settings['dnse_login_enabled'] );
    }

    /**
     * Tạo nonce + config để truyền cho JS.
     */
    public static function get_js_config( string $redirect_to = '' ): array {
        return [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'action'     => self::AJAX_ACTION,
            'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
            'redirectTo' => $redirect_to,
        ];
    }
}
