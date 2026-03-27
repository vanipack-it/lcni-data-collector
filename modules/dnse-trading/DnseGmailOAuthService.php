<?php
/**
 * DnseGmailOAuthService
 *
 * Quản lý Gmail OAuth2 Authorization Code flow để tự động đọc
 * email OTP từ DNSE và renew trading token.
 *
 * Flow:
 *  1. Admin cấu hình Google OAuth Client ID + Secret trong Settings
 *  2. User bấm "Kết nối Gmail" → redirect đến Google consent screen
 *  3. Google callback → lưu refresh_token (mã hoá AES-256)
 *  4. Cron mỗi phút: kiểm tra trading_token → nếu < 30 phút còn hạn:
 *       a. Gọi DNSE request_email_otp
 *       b. Chờ 10s → dùng refresh_token lấy access_token
 *       c. Đọc Gmail → parse OTP 6 số
 *       d. Gọi DNSE verify OTP → lưu trading_token mới
 *
 * Scopes cần: https://www.googleapis.com/auth/gmail.readonly
 *
 * @package LCNI_Data_Collector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LCNI_Dnse_Gmail_OAuth_Service {

    // Gmail API endpoints
    const GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const GMAIL_API_URL    = 'https://gmail.googleapis.com/gmail/v1';
    const GMAIL_SCOPE      = 'https://www.googleapis.com/auth/gmail.readonly';

    // WP option keys
    const OPT_CLIENT_ID     = 'lcni_dnse_gmail_client_id';
    const OPT_CLIENT_SECRET = 'lcni_dnse_gmail_client_secret';

    // User meta key (encrypted refresh token)
    const META_REFRESH_TOKEN = 'lcni_dnse_gmail_refresh_token';
    const META_GMAIL_ADDRESS = 'lcni_dnse_gmail_address';

    // WP action cho OAuth callback
    const CALLBACK_ACTION = 'lcni_dnse_gmail_callback';

    /** @var LCNI_DnseTradingRepository */
    private $repo;

    /** @var LCNI_DnseTradingApiClient */
    private $api;

    public function __construct(
        LCNI_DnseTradingRepository $repo,
        LCNI_DnseTradingApiClient  $api
    ) {
        $this->repo = $repo;
        $this->api  = $api;

        // Đăng ký callback URL handler
        add_action( 'init', [ $this, 'handle_oauth_callback' ] );
    }

    // =========================================================================
    // CONFIG
    // =========================================================================

    public static function get_client_id(): string {
        return (string) get_option( self::OPT_CLIENT_ID, '' );
    }

    public static function get_client_secret(): string {
        return (string) get_option( self::OPT_CLIENT_SECRET, '' );
    }

    public static function is_configured(): bool {
        return self::get_client_id() !== '' && self::get_client_secret() !== '';
    }

    /**
     * Callback URL đăng ký trong Google Cloud Console.
     * Phải là Authorized Redirect URI.
     */
    public static function get_redirect_uri(): string {
        return add_query_arg( 'action', self::CALLBACK_ACTION, admin_url( 'admin-post.php' ) );
    }

    // =========================================================================
    // STEP 1 — Build authorization URL (gửi user tới Google)
    // =========================================================================

    /**
     * Tạo URL Google consent screen.
     * User click → redirect tới Google → Google redirect về callback.
     *
     * @param int $user_id  WP user ID — lưu vào state để biết user nào đang connect
     */
    public function build_auth_url( int $user_id ): string {
        $state = $this->build_state_token( $user_id );

        return self::GOOGLE_AUTH_URL . '?' . http_build_query( [
            'client_id'             => self::get_client_id(),
            'redirect_uri'          => self::get_redirect_uri(),
            'response_type'         => 'code',
            'scope'                 => self::GMAIL_SCOPE,
            'access_type'           => 'offline',   // lấy refresh_token
            'prompt'                => 'consent',    // luôn hỏi để đảm bảo có refresh_token
            'include_granted_scopes'=> 'true',
            'state'                 => $state,
        ] );
    }

    // =========================================================================
    // STEP 2 — Handle callback (Google redirect về sau khi user đồng ý)
    // =========================================================================

    /**
     * Hook vào 'init' — xử lý khi Google redirect về với ?action=lcni_dnse_gmail_callback
     */
    public function handle_oauth_callback(): void {
        if ( ( $_GET['action'] ?? '' ) !== self::CALLBACK_ACTION ) {
            return;
        }

        $code  = sanitize_text_field( wp_unslash( $_GET['code']  ?? '' ) );
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
        $error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );

        // Lấy return URL từ state trước để redirect về đúng chỗ
        $user_id    = $this->verify_state_token( $state );
        $return_url = $this->get_return_url( $user_id );

        if ( $error !== '' || $code === '' ) {
            wp_safe_redirect( add_query_arg( [
                'lcni_gmail_status' => 'error',
                'lcni_gmail_msg'    => urlencode( $error ?: 'Không nhận được code từ Google.' ),
            ], $return_url ) );
            exit;
        }

        if ( ! $user_id ) {
            wp_safe_redirect( add_query_arg( 'lcni_gmail_status', 'invalid_state', $return_url ) );
            exit;
        }

        // Đổi code lấy tokens
        $tokens = $this->exchange_code_for_tokens( $code );
        if ( is_wp_error( $tokens ) ) {
            wp_safe_redirect( add_query_arg( [
                'lcni_gmail_status' => 'error',
                'lcni_gmail_msg'    => urlencode( $tokens->get_error_message() ),
            ], $return_url ) );
            exit;
        }

        // Lưu refresh_token (mã hoá) + gmail address vào user meta
        $this->save_refresh_token( $user_id, $tokens['refresh_token'] );
        if ( ! empty( $tokens['email'] ) ) {
            update_user_meta( $user_id, self::META_GMAIL_ADDRESS, sanitize_email( $tokens['email'] ) );
        }

        error_log( "[LCNI DNSE] Gmail OAuth connected for user {$user_id}" );

        wp_safe_redirect( add_query_arg( 'lcni_gmail_status', 'success', $return_url ) );
        exit;
    }

    // =========================================================================
    // STEP 3 — Exchange authorization code for tokens
    // =========================================================================

    /**
     * @return array|WP_Error  ['access_token'=>..., 'refresh_token'=>..., 'email'=>...]
     */
    private function exchange_code_for_tokens( string $code ) {
        $response = wp_remote_post( self::GOOGLE_TOKEN_URL, [
            'timeout' => 15,
            'body'    => [
                'code'          => $code,
                'client_id'     => self::get_client_id(),
                'client_secret' => self::get_client_secret(),
                'redirect_uri'  => self::get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'gmail_token_exchange', 'Lỗi kết nối Google: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['refresh_token'] ) ) {
            $err = $body['error_description'] ?? $body['error'] ?? 'Không nhận được refresh_token.';
            return new WP_Error( 'gmail_no_refresh', $err );
        }

        // Lấy email từ id_token (nếu có) để hiển thị trong UI
        $email = '';
        if ( ! empty( $body['id_token'] ) ) {
            $parts   = explode( '.', $body['id_token'] );
            $payload = json_decode( base64_decode( str_pad(
                strtr( $parts[1] ?? '', '-_', '+/' ),
                strlen( $parts[1] ?? '' ) % 4 ? strlen( $parts[1] ?? '' ) + 4 - strlen( $parts[1] ?? '' ) % 4 : strlen( $parts[1] ?? '' ),
                '='
            ) ), true );
            $email = sanitize_email( $payload['email'] ?? '' );
        }

        return [
            'access_token'  => $body['access_token'],
            'refresh_token' => $body['refresh_token'],
            'email'         => $email,
        ];
    }

    // =========================================================================
    // STEP 4 — Get fresh access_token từ refresh_token
    // =========================================================================

    /**
     * Lấy access_token mới từ refresh_token đã lưu.
     *
     * @return string|WP_Error  access_token string
     */
    public function get_access_token( int $user_id ) {
        $refresh_token = $this->get_refresh_token( $user_id );
        if ( $refresh_token === '' ) {
            return new WP_Error( 'gmail_no_refresh', 'Chưa kết nối Gmail. Vui lòng kết nối lại.' );
        }

        $response = wp_remote_post( self::GOOGLE_TOKEN_URL, [
            'timeout' => 10,
            'body'    => [
                'client_id'     => self::get_client_id(),
                'client_secret' => self::get_client_secret(),
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'gmail_refresh_failed', 'Lỗi kết nối Google: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            // refresh_token bị revoke → xoá để user biết cần kết nối lại
            if ( ( $body['error'] ?? '' ) === 'invalid_grant' ) {
                $this->disconnect( $user_id );
            }
            $err = $body['error_description'] ?? $body['error'] ?? 'Không lấy được access_token.';
            return new WP_Error( 'gmail_no_access', $err );
        }

        return $body['access_token'];
    }

    // =========================================================================
    // STEP 5 — Đọc OTP từ Gmail
    // =========================================================================

    /**
     * Đọc mã OTP 6 số từ email DNSE gửi gần nhất (trong 5 phút).
     * Gọi sau khi đã trigger request_email_otp và chờ đủ thời gian.
     *
     * @param string $access_token  Gmail API access token
     * @return string|WP_Error  OTP 6 chữ số
     */
    public function fetch_otp_from_gmail( string $access_token, int $sent_after = 0 ) {
        // Tìm email từ DNSE gửi sau thời điểm request OTP (tránh lấy OTP cũ còn trong inbox)
        // Nếu không có sent_after → fallback 5 phút gần nhất
        $after_ts = $sent_after > 0 ? ( $sent_after - 30 ) : ( time() - 300 );

        // Domain thực tế: noreply@mail.dnse.com.vn — dùng wildcard @dnse.com.vn bắt tất cả subdomain
        $query = sprintf( 'from:(@dnse.com.vn) after:%d', $after_ts );

        $list_url = self::GMAIL_API_URL . '/users/me/messages?' . http_build_query( [
            'q'          => $query,
            'maxResults' => 5,
        ] );

        $list_response = wp_remote_get( $list_url, [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );

        if ( is_wp_error( $list_response ) ) {
            return new WP_Error( 'gmail_list_failed', 'Lỗi đọc Gmail: ' . $list_response->get_error_message() );
        }

        $list_body = json_decode( wp_remote_retrieve_body( $list_response ), true );

        if ( empty( $list_body['messages'] ) ) {
            return new WP_Error( 'gmail_no_otp_email', 'Không tìm thấy email OTP từ DNSE (trong 5 phút gần nhất).' );
        }

        // Lấy email mới nhất (messages đã được sort theo newest)
        $message_id = $list_body['messages'][0]['id'];

        // Fetch nội dung email
        $msg_url = self::GMAIL_API_URL . '/users/me/messages/' . urlencode( $message_id ) . '?format=full';
        $msg_response = wp_remote_get( $msg_url, [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );

        if ( is_wp_error( $msg_response ) ) {
            return new WP_Error( 'gmail_msg_failed', 'Lỗi đọc nội dung email.' );
        }

        $msg_body = json_decode( wp_remote_retrieve_body( $msg_response ), true );

        // Extract text từ email body (plain text ưu tiên, fallback HTML)
        $text = $this->extract_email_text( $msg_body );

        // Thử parse từ snippet trước — Gmail snippet thường có OTP dạng số liền sạch hơn HTML
        $snippet = (string) ( $msg_body['snippet'] ?? '' );
        if ( $snippet !== '' ) {
            $otp_from_snippet = $this->parse_otp_from_text( html_entity_decode( $snippet, ENT_QUOTES, 'UTF-8' ) );
            if ( $otp_from_snippet !== '' ) {
                error_log( '[LCNI DNSE] Gmail OTP from snippet: ' . $otp_from_snippet );
                return $otp_from_snippet;
            }
        }

        if ( $text === '' ) {
            return new WP_Error( 'gmail_empty_body', 'Không đọc được nội dung email OTP.' );
        }

        // Parse OTP 6 chữ số
        // Pattern: mã OTP thường đứng riêng một dòng hoặc sau từ khóa
        // Log raw text để debug format thực tế của DNSE email
        error_log( '[LCNI DNSE] Email text (200 chars): ' . substr( $text, 0, 200 ) );
        error_log( '[LCNI DNSE] Email text hex (100 chars): ' . bin2hex( substr( $text, 0, 100 ) ) );

        $otp = $this->parse_otp_from_text( $text );

        if ( $otp === '' ) {
            // Log cả raw text để debug parse failure
            error_log( '[LCNI DNSE] Gmail OTP parse failed. Text (200 chars): ' . substr( $text, 0, 200 ) );
            error_log( '[LCNI DNSE] Gmail OTP parse failed. Text hex (50 chars): ' . bin2hex( substr( $text, 0, 50 ) ) );
            return new WP_Error( 'gmail_otp_not_found', 'Không parse được mã OTP 6 chữ số từ email.' );
        }

        error_log( '[LCNI DNSE] Gmail OTP parsed successfully: ' . $otp );
        return $otp;
    }

    /**
     * Extract plain text từ Gmail message payload.
     */
    private function extract_email_text( array $msg ): string {
        $payload = $msg['payload'] ?? [];

        // Tìm phần text/plain trước
        $text = $this->find_part_text( $payload, 'text/plain' );
        if ( $text !== '' ) return $text;

        // Fallback: text/html → strip tags
        $html = $this->find_part_text( $payload, 'text/html' );
        if ( $html !== '' ) return wp_strip_all_tags( $html );

        // Fallback cuối: snippet
        return (string) ( $msg['snippet'] ?? '' );
    }

    /**
     * Duyệt đệ quy parts của Gmail message để tìm MIME type cụ thể.
     */
    private function find_part_text( array $payload, string $mime_type ): string {
        $type = $payload['mimeType'] ?? '';

        if ( $type === $mime_type ) {
            $data = $payload['body']['data'] ?? '';
            if ( $data !== '' ) {
                return (string) base64_decode( strtr( $data, '-_', '+/' ) );
            }
        }

        // Duyệt parts con
        foreach ( $payload['parts'] ?? [] as $part ) {
            $found = $this->find_part_text( $part, $mime_type );
            if ( $found !== '' ) return $found;
        }

        return '';
    }

    /**
     * Parse mã OTP 6 chữ số từ text email.
     *
     * DNSE email thường có format:
     *   "Mã OTP của bạn là: 123456"
     *   "Your OTP code: 123456"
     *   Hoặc mã đứng riêng một dòng
     */
    private function parse_otp_from_text( string $text ): string {
        // Ưu tiên 1: 6 chữ số liền sau keyword OTP
        $patterns = [
            '/(?:mã\s+otp|otp\s+code|otp\s+của\s+bạn|your\s+otp|mã\s+xác\s+thực|verification\s+code)[^\d]*(\d{6})/i',
            '/(?:là|is|:)\s*(\d{6})\b/i',
            '/^\s*(\d{6})\s*$/m',
            '/\b(\d{6})\b/',
        ];
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $text, $matches ) ) {
                return $matches[1];
            }
        }

        // Ưu tiên 2: DNSE render OTP dạng "1 6 8 3 1 4" — 6 chữ số đơn cách nhau bằng whitespace
        // Dùng \s+ để bắt cả 1 space, 2 space, tab, newline
        if ( preg_match( '/\b(\d)\s+(\d)\s+(\d)\s+(\d)\s+(\d)\s+(\d)\b/', $text, $m ) ) {
            return $m[1] . $m[2] . $m[3] . $m[4] . $m[5] . $m[6];
        }

        // Ưu tiên 3: tìm tất cả chữ số đơn (\b\d\b) — nếu đúng 6 cái liên tiếp thì đó là OTP
        $single_digits = [];
        if ( preg_match_all( '/\b\d\b/', $text, $all ) ) {
            $single_digits = $all[0];
        }
        if ( count( $single_digits ) === 6 ) {
            return implode( '', $single_digits );
        }

        return '';
    }

    // =========================================================================
    // AUTO-RENEW TRADING TOKEN — Entry point cho cron
    // =========================================================================

    /**
     * Tự động renew trading token cho user.
     *
     * Quy trình:
     *  1. Kiểm tra còn < 30 phút → cần renew
     *  2. Lấy JWT hợp lệ
     *  3. Gọi DNSE gửi Email OTP
     *  4. Chờ 12 giây cho email đến
     *  5. Lấy access_token Gmail → đọc OTP từ inbox
     *  6. Gọi DNSE verify OTP → lưu trading_token mới
     *
     * @return true|false|WP_Error
     *   true  = renew thành công hoặc token còn đủ hạn
     *   false = user không có Gmail kết nối → bỏ qua
     */
    public function auto_renew_trading_token( int $user_id ) {
        // Không có refresh_token → user chưa kết nối Gmail
        $refresh_token = $this->get_refresh_token( $user_id );
        if ( $refresh_token === '' ) {
            return false;
        }

        // Token còn > 1 giờ → chưa cần renew
        // Buffer 1h (thay vì 30 phút) để đảm bảo renew luôn xảy ra khi JWT còn hạn.
        // JWT và trading token cùng hết hạn 8h kể từ lúc login → nếu buffer quá nhỏ (30 phút),
        // JWT có thể đã hết hạn trước khi cron kịp trigger → auto-renew thất bại → phải login lại.
        $creds      = $this->repo->get_credentials( $user_id );
        $expires_at = (int) ( $creds['trading_expires_at'] ?? 0 );
        if ( $expires_at > time() + HOUR_IN_SECONDS ) {
            return true;
        }

        // Lấy JWT — nếu hết hạn thì tự re-login bằng password đã lưu
        if ( ! $this->repo->is_jwt_valid( $user_id ) ) {
            $password = $this->repo->get_password( $user_id );
            if ( $password === '' ) {
                return new WP_Error(
                    'dnse_jwt_expired_no_pass',
                    "User {$user_id}: JWT hết hạn và không có password lưu — cần đăng nhập lại thủ công."
                );
            }

            // Lấy username từ credentials
            $creds_check = $this->repo->get_credentials( $user_id );
            $username    = $creds_check['dnse_account_no'] ?? '';
            if ( $username === '' ) {
                return new WP_Error( 'dnse_no_username', "User {$user_id}: không có username DNSE." );
            }

            // Tự động re-login để lấy JWT mới
            $login = $this->api->login( $username, $password );
            if ( is_wp_error( $login ) ) {
                return new WP_Error(
                    'dnse_relogin_failed',
                    "User {$user_id}: tự động đăng nhập lại thất bại — " . $login->get_error_message()
                );
            }

            $this->repo->save_jwt_token( $user_id, $login['jwt'], $login['expires_at'], $username );
            error_log( "[LCNI DNSE] Auto re-login JWT for user {$user_id}, expires " . date( 'H:i d/m', $login['expires_at'] ) );
        }

        $creds_full = $this->repo->get_credentials( $user_id );
        $jwt        = $creds_full['jwt_token'] ?? '';
        if ( $jwt === '' ) {
            return new WP_Error( 'dnse_no_jwt', "User {$user_id}: không đọc được JWT sau re-login." );
        }

        // Gọi DNSE gửi Email OTP
        $sent = $this->api->request_email_otp( $jwt );
        if ( is_wp_error( $sent ) ) {
            return new WP_Error(
                'dnse_send_otp_failed',
                "User {$user_id}: " . $sent->get_error_message()
            );
        }

        $sent_at = time(); // ghi lại trước khi sleep để filter OTP cũ

        // Chờ email đến (12 giây)
        sleep( 12 );

        // Lấy access_token Gmail
        $access_token = $this->get_access_token( $user_id );
        if ( is_wp_error( $access_token ) ) {
            return new WP_Error(
                'gmail_token_failed',
                "User {$user_id}: " . $access_token->get_error_message()
            );
        }

        // Retry đọc Gmail tối đa 3 lần cách nhau 8 giây (email có thể chậm)
        $otp = null;
        for ( $try = 1; $try <= 3; $try++ ) {
            $result = $this->fetch_otp_from_gmail( $access_token, $sent_at );
            if ( ! is_wp_error( $result ) ) {
                $otp = $result;
                break;
            }
            if ( $try < 3 ) {
                sleep( 8 );
            }
        }

        if ( $otp === null ) {
            return new WP_Error(
                'gmail_otp_read_failed',
                "User {$user_id}: không đọc được OTP sau 3 lần thử."
            );
        }

        // Gọi DNSE lấy trading token
        $token_result = $this->api->get_trading_token_by_email_otp( $jwt, $otp );
        if ( is_wp_error( $token_result ) ) {
            return new WP_Error(
                'dnse_verify_otp_failed',
                "User {$user_id}: " . $token_result->get_error_message()
            );
        }

        $this->repo->save_trading_token(
            $user_id,
            $token_result['trading_token'],
            $token_result['expires_at']
        );

        $new_exp = date( 'H:i d/m', $token_result['expires_at'] );
        error_log( "[LCNI DNSE] Auto-renewed trading token for user {$user_id} via Gmail OTP, expires {$new_exp}" );

        return true;
    }

    // =========================================================================
    // DISCONNECT
    // =========================================================================

    public function disconnect( int $user_id ): void {
        delete_user_meta( $user_id, self::META_REFRESH_TOKEN );
        delete_user_meta( $user_id, self::META_GMAIL_ADDRESS );
        error_log( "[LCNI DNSE] Gmail disconnected for user {$user_id}" );
    }

    /**
     * Gửi email OTP từ DNSE — dùng bởi /connect khi muốn auto OTP async.
     * Tách ra khỏi auto_renew để controller chủ động gọi.
     *
     * @return true|WP_Error
     */
    public function send_email_otp( int $user_id, string $jwt ) {
        $sent = $this->api->request_email_otp( $jwt );
        if ( is_wp_error( $sent ) ) {
            return new WP_Error( 'dnse_send_otp_failed', $sent->get_error_message() );
        }
        return true;
    }

    /**
     * Verify OTP đã đọc được từ Gmail, lưu trading token.
     * Dùng bởi /gmail-otp-poll sau khi fetch_otp_from_gmail() thành công.
     *
     * @return true|WP_Error
     */
    public function verify_email_otp( int $user_id, string $jwt, string $otp ) {
        $token_result = $this->api->get_trading_token_by_email_otp( $jwt, $otp );
        if ( is_wp_error( $token_result ) ) {
            error_log( "[LCNI DNSE] verify_email_otp FAILED user={$user_id} otp={$otp}: " . $token_result->get_error_message() );
            return $token_result;
        }
        $this->repo->save_trading_token(
            $user_id,
            $token_result['trading_token'],
            $token_result['expires_at']
        );
        $new_exp = date( 'H:i d/m', $token_result['expires_at'] );
        error_log( "[LCNI DNSE] Trading token saved for user {$user_id} via Gmail poll, expires {$new_exp}" );
        return true;
    }

    public function is_connected( int $user_id ): bool {
        return $this->get_refresh_token( $user_id ) !== '';
    }

    public function get_connected_email( int $user_id ): string {
        return (string) get_user_meta( $user_id, self::META_GMAIL_ADDRESS, true );
    }

    // =========================================================================
    // STORAGE — refresh_token mã hoá trong user_meta
    // =========================================================================

    private function save_refresh_token( int $user_id, string $token ): void {
        $enc = $this->encrypt( $token );
        if ( $enc !== false ) {
            update_user_meta( $user_id, self::META_REFRESH_TOKEN, $enc );
        }
    }

    private function get_refresh_token( int $user_id ): string {
        $enc = get_user_meta( $user_id, self::META_REFRESH_TOKEN, true );
        if ( ! $enc ) return '';
        return $this->decrypt( (string) $enc );
    }

    // =========================================================================
    // STATE TOKEN — CSRF protection cho OAuth callback
    // =========================================================================

    private function build_state_token( int $user_id ): string {
        $nonce = wp_create_nonce( 'lcni_gmail_oauth_' . $user_id );
        // Lưu user_id vào transient để lấy lại ở callback
        set_transient( 'lcni_gmail_state_' . $nonce, $user_id, 10 * MINUTE_IN_SECONDS );
        return $nonce;
    }

    private function verify_state_token( string $state ): int {
        if ( $state === '' ) return 0;
        $user_id = (int) get_transient( 'lcni_gmail_state_' . $state );
        if ( $user_id > 0 ) {
            delete_transient( 'lcni_gmail_state_' . $state );
        }
        return $user_id;
    }

    private function get_return_url( int $user_id ): string {
        // Redirect về trang dashboard hoặc profile sau OAuth
        if ( function_exists( 'stock_dashboard_theme_get_dashboard_url' ) ) {
            return stock_dashboard_theme_get_dashboard_url();
        }
        return home_url( '/' );
    }

    // =========================================================================
    // ENCRYPTION — cùng cơ chế AES-256 với DnseTradingRepository
    // =========================================================================

    private function get_secret(): string {
        return defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
    }

    private function encrypt( string $plaintext ) {
        if ( $plaintext === '' || ! function_exists( 'openssl_encrypt' ) ) {
            return base64_encode( $plaintext );
        }
        $key = hash( 'sha256', $this->get_secret(), true );
        $iv  = random_bytes( 16 );
        $enc = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return $enc !== false ? base64_encode( $iv . $enc ) : false;
    }

    private function decrypt( string $ciphertext ): string {
        if ( $ciphertext === '' ) return '';
        $key     = hash( 'sha256', $this->get_secret(), true );
        $decoded = base64_decode( $ciphertext );
        if ( ! $decoded || strlen( $decoded ) < 16 ) return '';
        $iv  = substr( $decoded, 0, 16 );
        $enc = substr( $decoded, 16 );
        if ( ! function_exists( 'openssl_decrypt' ) ) return $enc;
        $dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return $dec !== false ? $dec : '';
    }
}
