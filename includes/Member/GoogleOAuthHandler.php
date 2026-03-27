<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LCNI Google OAuth Handler
 *
 * Xử lý đăng nhập / đăng ký bằng Google One Tap (GIS).
 * Tích hợp vào MemberModule: new LCNI_Google_OAuth_Handler();
 *
 * Yêu cầu:
 *  - lcni_google_client_id  (option) — Client ID từ Google Cloud Console
 *  - lcni_member_login_settings.redirect_url (tùy chọn)
 *  - lcni_member_register_settings.default_role (tùy chọn)
 */
class LCNI_Google_OAuth_Handler {

    public function __construct() {
        add_action( 'wp_ajax_nopriv_lcni_google_login', [ $this, 'handle' ] );
        add_action( 'wp_ajax_lcni_google_login',        [ $this, 'handle' ] );
    }

    // =========================================================
    // AJAX handler
    // =========================================================

    public function handle() {
        // 1. Verify nonce
        if ( empty( $_POST['nonce'] )
            || ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['nonce'] ) ),
                'lcni_google_auth_nonce'
            )
        ) {
            wp_send_json_error( 'Nonce không hợp lệ.' );
        }

        // 2. Lấy credential (JWT id_token từ Google)
        $id_token  = sanitize_text_field( wp_unslash( $_POST['credential'] ?? '' ) );
        $client_id = get_option( 'lcni_google_client_id', '' );

        if ( $id_token === '' || $client_id === '' ) {
            wp_send_json_error( 'Thiếu token hoặc Client ID.' );
        }

        // 3. Xác minh token với Google tokeninfo endpoint
        $payload = $this->verify_id_token( $id_token, $client_id );
        if ( is_wp_error( $payload ) ) {
            wp_send_json_error( $payload->get_error_message() );
        }

        // 4. Lấy thông tin user từ payload
        $email     = sanitize_email( $payload['email'] ?? '' );
        $name      = sanitize_text_field( $payload['name'] ?? '' );
        $google_id = sanitize_text_field( $payload['sub'] ?? '' );
        $avatar    = esc_url_raw( $payload['picture'] ?? '' );

        if ( ! is_email( $email ) || $google_id === '' ) {
            wp_send_json_error( 'Dữ liệu Google không hợp lệ.' );
        }

        // 5. Tìm hoặc tạo WP user
        $user = $this->find_or_create_user( $email, $name, $google_id, $avatar );
        if ( is_wp_error( $user ) ) {
            wp_send_json_error( $user->get_error_message() );
        }

        // 6. Đăng nhập
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        // 7. Xác định redirect_url (ưu tiên: POST param → settings → home)
        $from_post = isset( $_POST['redirect_to'] )
            ? wp_validate_redirect( (string) wp_unslash( $_POST['redirect_to'] ), '' )
            : '';
        $settings  = get_option( 'lcni_member_login_settings', [] );
        $from_opt  = ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : '';
        $redirect  = $from_post !== '' ? $from_post : ( $from_opt !== '' ? $from_opt : home_url( '/' ) );

        wp_send_json_success( [ 'redirect' => $redirect ] );
    }

    // =========================================================
    // Xác minh id_token với Google
    // =========================================================

    /**
     * Gọi tokeninfo API của Google để xác minh JWT.
     * Trả về payload array hoặc WP_Error.
     *
     * @param string $id_token
     * @param string $client_id
     * @return array|WP_Error
     */
    private function verify_id_token( string $id_token, string $client_id ) {
        $response = wp_remote_get(
            add_query_arg( 'id_token', rawurlencode( $id_token ), 'https://oauth2.googleapis.com/tokeninfo' ),
            [ 'timeout' => 8 ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'google_api', 'Không thể kết nối Google API.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'google_token', 'Token không hợp lệ (HTTP ' . $code . ').' );
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'google_parse', 'Không parse được response từ Google.' );
        }

        // Xác thực audience — bắt buộc để tránh token từ app khác
        if ( ( $payload['aud'] ?? '' ) !== $client_id ) {
            return new WP_Error( 'google_aud', 'Client ID không khớp.' );
        }

        // Xác thực email đã verify
        if ( ( $payload['email_verified'] ?? 'false' ) !== 'true' ) {
            return new WP_Error( 'google_email', 'Email Google chưa được xác minh.' );
        }

        // Xác thực token chưa hết hạn
        $exp = (int) ( $payload['exp'] ?? 0 );
        if ( $exp > 0 && $exp < time() ) {
            return new WP_Error( 'google_exp', 'Token đã hết hạn.' );
        }

        return $payload;
    }

    // =========================================================
    // Tìm hoặc tạo WP user
    // =========================================================

    /**
     * @return WP_User|WP_Error
     */
    private function find_or_create_user( string $email, string $name, string $google_id, string $avatar ) {
        // Ưu tiên tìm theo google_id (user có thể đổi email)
        $existing = get_users( [
            'meta_key'   => 'lcni_google_id',
            'meta_value' => $google_id,
            'number'     => 1,
        ] );

        if ( ! empty( $existing ) ) {
            $user = $existing[0];
            // Cập nhật display_name nếu đổi
            if ( $user->display_name !== $name && $name !== '' ) {
                wp_update_user( [ 'ID' => $user->ID, 'display_name' => $name ] );
            }
            return $user;
        }

        // Tìm theo email
        $user = get_user_by( 'email', $email );
        if ( $user ) {
            // Gắn google_id để lần sau tìm nhanh hơn
            update_user_meta( $user->ID, 'lcni_google_id', $google_id );
            if ( $avatar !== '' ) {
                update_user_meta( $user->ID, 'lcni_google_avatar', $avatar );
            }
            return $user;
        }

        // Tạo user mới
        $settings = get_option( 'lcni_member_register_settings', [] );
        $role     = ! empty( $settings['default_role'] ) ? sanitize_key( $settings['default_role'] ) : 'subscriber';

        $username = $this->generate_unique_username( $email );
        $password = wp_generate_password( 24, true, true );

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $email,
            'display_name' => $name !== '' ? $name : $username,
            'role'         => $role,
        ] );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        update_user_meta( $user_id, 'lcni_google_id',     $google_id );
        update_user_meta( $user_id, 'lcni_google_avatar', $avatar );

        /**
         * Hook: lcni_google_user_created
         * Cho phép module khác (vd: gán gói mặc định) xử lý sau khi tạo user.
         *
         * @param int    $user_id
         * @param string $email
         * @param string $google_id
         */
        do_action( 'lcni_google_user_created', $user_id, $email, $google_id );

        // Gửi email chào mừng đăng ký Google
        if ( class_exists( 'LCNINotificationManager' ) ) {
            LCNINotificationManager::send( 'register_success', $email, [
                'user_name'  => $name ?: $username,
                'user_email' => $email,
            ] );
        }

        return get_user_by( 'id', $user_id );
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Sinh username duy nhất từ email.
     * Nếu phần local đã tồn tại thì append số tăng dần.
     */
    private function generate_unique_username( string $email ): string {
        $base     = sanitize_user( strstr( $email, '@', true ), true );
        $base     = $base !== '' ? $base : 'user';
        $username = $base;
        $i        = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $i++;
        }
        return $username;
    }
}
