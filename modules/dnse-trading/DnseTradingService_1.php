<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DnseTradingService
 *
 * Business logic layer: orchestrate API calls + caching trong DB.
 * Đây là class duy nhất mà các shortcode/controller gọi.
 */
class LCNI_DnseTradingService {

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
    }

    // =========================================================================
    // AUTHENTICATION FLOW
    // =========================================================================

    /**
     * Bước 1: Đăng nhập DNSE với username + password.
     * Lưu jwt-token vào DB, KHÔNG lưu password.
     * Tự động lấy danh sách tiểu khoản sau khi login.
     *
     * @return array|WP_Error  ['success' => true, 'sub_accounts' => [...]]
     */
    public function connect( int $user_id, string $username, string $password, array $permissions = [] ) {
        $login = $this->api->login( $username, $password );
        if ( is_wp_error( $login ) ) return $login;

        $jwt        = $login['jwt'];
        $expires_at = $login['expires_at'];

        $saved = $this->repo->save_jwt_token( $user_id, $jwt, $expires_at, $username );
        if ( ! $saved ) {
            return new WP_Error( 'dnse_save_failed', 'Không thể lưu token vào DB.' );
        }

        // Lưu permissions user đã chọn
        $this->repo->save_permissions( $user_id, $permissions );

        // Chỉ lưu password nếu user cho phép auto re-login
        if ( in_array( 'perm_auto_renew', $permissions, true ) ) {
            $this->repo->save_password( $user_id, $password );
        } else {
            $this->repo->delete_password( $user_id );
        }

        $sub_accounts = $this->api->get_sub_accounts( $jwt );
        if ( ! is_wp_error( $sub_accounts ) && ! empty( $sub_accounts ) ) {
            $this->repo->save_sub_accounts( $user_id, $sub_accounts );
        }

        return [
            'success'      => true,
            'sub_accounts' => is_wp_error( $sub_accounts ) ? [] : $sub_accounts,
            'permissions'  => $permissions,
        ];
    }

    /**
     * Bước 2a: Yêu cầu gửi Email OTP.
     * Gọi trước khi user nhập OTP.
     */
    public function request_email_otp( int $user_id ) {
        // Dùng get_jwt_for_auto_otp (có re-login) thay vì get_valid_jwt
        // để tự refresh JWT khi hết hạn — tránh DNSE trả 500 do JWT cũ
        $jwt = $this->get_jwt_for_auto_otp( $user_id );
        if ( is_wp_error( $jwt ) ) return $jwt;

        return $this->api->request_email_otp( $jwt );
    }

    /**
     * Bước 2b: Xác thực OTP, lưu trading-token.
     * Gọi sau khi user nhập OTP từ Email hoặc Smart OTP app.
     *
     * @param string $otp_type  'smart' | 'email'
     */
    public function authenticate_otp( int $user_id, string $otp, string $otp_type = 'smart' ) {
        // Dùng get_jwt_for_auto_otp để tự refresh JWT nếu hết hạn
        $jwt = $this->get_jwt_for_auto_otp( $user_id );
        if ( is_wp_error( $jwt ) ) return $jwt;

        $result = $otp_type === 'email'
            ? $this->api->get_trading_token_by_email_otp( $jwt, $otp )
            : $this->api->get_trading_token_by_smart_otp( $jwt, $otp );

        if ( is_wp_error( $result ) ) return $result;

        $this->repo->save_trading_token(
            $user_id,
            $result['trading_token'],
            $result['expires_at']
        );

        return true;
    }

    /**
     * Ngắt kết nối — xóa toàn bộ tokens.
     */
    public function disconnect( int $user_id ): void {
        $this->repo->delete_password( $user_id );
        $this->repo->revoke_credentials( $user_id );
    }

    /**
     * Trạng thái kết nối hiện tại của user.
     */
    public function get_connection_status( int $user_id ): array {
        $creds = $this->repo->get_credentials( $user_id );
        if ( ! $creds ) {
            return [ 'connected' => false, 'has_trading' => false ];
        }

        $jwt_valid     = $this->repo->is_jwt_valid( $user_id );
        $trading_valid = $this->repo->is_trading_token_valid( $user_id );
        $sub_accounts_raw = json_decode( (string) ( $creds['sub_accounts_json'] ?? '' ), true ) ?: [];
        $sub_accounts     = $this->normalize_sub_accounts( $sub_accounts_raw );

        return [
            'connected'          => $jwt_valid,
            'has_trading'        => $trading_valid,
            'account_no'         => $creds['dnse_account_no'] ?? '',
            'sub_accounts'       => $sub_accounts,
            'connected_at'       => $creds['connected_at'] ?? '',
            'last_sync_at'       => $creds['last_sync_at'] ?? '',
            'jwt_expires_at'     => (int) ( $creds['jwt_expires_at'] ?? 0 ),
            'trading_expires_at' => (int) ( $creds['trading_expires_at'] ?? 0 ),
            'permissions'        => $this->repo->get_permissions( $user_id ),
        ];
    }

    // =========================================================================
    // SYNC — đọc dữ liệu từ DNSE về DB
    // =========================================================================

    /**
     * Sync toàn bộ: balance + positions + orders cho tất cả tiểu khoản.
     * Gọi theo cron hoặc khi user bấm "Làm mới".
     *
     * @return array  ['synced_accounts' => int, 'errors' => [...]]
     */
    public function sync_all( int $user_id ): array {
        if ( ! $this->repo->has_permission( $user_id, 'perm_sync' ) ) {
            return [ 'synced_accounts' => 0, 'errors' => [ 'Chưa được cấp quyền đồng bộ dữ liệu.' ] ];
        }

        $jwt = $this->get_valid_jwt( $user_id );
        if ( is_wp_error( $jwt ) ) {
            return [ 'synced_accounts' => 0, 'errors' => [ $jwt->get_error_message() ] ];
        }

        $creds = $this->repo->get_credentials( $user_id );
        $sub_accounts_json = json_decode( (string) ( $creds['sub_accounts_json'] ?? '' ), true ) ?: [];
        $sub_accounts_raw  = $this->normalize_sub_accounts( $sub_accounts_json );

        if ( empty( $sub_accounts_raw ) ) {
            $fresh = $this->api->get_sub_accounts( $jwt );
            if ( ! is_wp_error( $fresh ) && ! empty( $fresh ) ) {
                $sub_accounts_raw = $fresh;
                $this->repo->save_sub_accounts( $user_id, $sub_accounts_raw );
            }
        }

        if ( empty( $sub_accounts_raw ) ) {
            return [ 'synced_accounts' => 0, 'errors' => [ 'Không lấy được danh sách tiểu khoản.' ] ];
        }

        $synced = 0;
        $errors = [];

        foreach ( $sub_accounts_raw as $acct ) {
            $account_no = (string) ( $acct['investorAccountNo'] ?? $acct['id'] ?? $acct['accountNo'] ?? '' );
            if ( $account_no === '' ) continue;

            // Xác định loại tài khoản (spot / margin)
            $type = $this->detect_account_type( $acct );

            // Balance
            $balance = $this->api->get_account_balance( $jwt, $account_no, $type );
            if ( is_wp_error( $balance ) ) {
                $errors[] = "Balance {$account_no}: " . $balance->get_error_message();
                $balance  = [];
            }

            // Trade capacities
            $capacity = $this->api->get_trade_capacities( $jwt, $account_no, $type );
            if ( is_wp_error( $capacity ) ) {
                $capacity = [];
            }

            $type_name = (string) ( $acct['accountTypeName'] ?? $acct['accountTypeBriefName'] ?? '' );
            $this->repo->upsert_account( $user_id, $account_no, $type, $balance, $capacity, $type_name );

            // Positions
            $positions = $this->api->get_positions( $jwt, $account_no, $type );
            if ( ! is_wp_error( $positions ) ) {
                $this->repo->upsert_positions( $user_id, $account_no, $type, $positions );
            } else {
                $errors[] = "Positions {$account_no}: " . $positions->get_error_message();
            }

            // Orders (hôm nay)
            $orders = $this->api->get_orders( $jwt, $account_no, $type );
            if ( ! is_wp_error( $orders ) ) {
                $this->repo->upsert_orders( $user_id, $account_no, $type, $orders );
            } else {
                $errors[] = "Orders {$account_no}: " . $orders->get_error_message();
            }

            $synced++;
        }

        $this->repo->update_last_sync( $user_id );

        // Auto-sync lệnh đã khớp vào Portfolio (nếu module Portfolio có sẵn)
        if ( class_exists( 'LCNI_Portfolio_Service' ) && class_exists( 'LCNI_Portfolio_Repository' ) ) {
            try {
                $pf_repo    = new LCNI_Portfolio_Repository();
                $pf_service = new LCNI_Portfolio_Service( $pf_repo );
                foreach ( $sub_accounts_raw as $acct ) {
                    $acct_no   = (string) ( $acct['id'] ?? $acct['investorAccountNo'] ?? '' );
                    $type_name = (string) ( $acct['accountTypeName'] ?? '' );
                    if ( $acct_no !== '' ) {
                        $pf_service->sync_dnse_orders_to_portfolio( $user_id, $acct_no, $type_name );
                    }
                }
            } catch ( Throwable $e ) {
                error_log( '[LCNI DNSE] Portfolio sync error: ' . $e->getMessage() );
            }
        }

        return [ 'synced_accounts' => $synced, 'errors' => $errors ];
    }

    // =========================================================================
    // READ — đọc từ cache DB
    // =========================================================================

    public function get_dashboard_data( int $user_id ): array {
        $status    = $this->get_connection_status( $user_id );
        $accounts  = $this->repo->get_accounts( $user_id );
        $positions = $this->repo->get_positions( $user_id );
        $orders    = $this->repo->get_orders( $user_id, '', 20 );

        // Tính tổng portfolio
        $total_market_value = 0.0;
        $total_cost         = 0.0;
        foreach ( $positions as $pos ) {
            $total_market_value += (float) $pos['market_value'];
            $total_cost         += (float) $pos['quantity'] * (float) $pos['avg_price'] * 1000;
        }
        $total_pnl     = $total_market_value - $total_cost;
        $total_pnl_pct = $total_cost > 0 ? $total_pnl / $total_cost * 100 : 0;

        return [
            'status'             => $status,
            'accounts'           => $accounts,
            'positions'          => $positions,
            'orders'             => $orders,
            'portfolio_summary'  => [
                'total_market_value' => $total_market_value,
                'total_cost'         => $total_cost,
                'total_pnl'          => $total_pnl,
                'total_pnl_pct'      => round( $total_pnl_pct, 2 ),
                'position_count'     => count( $positions ),
            ],
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Lấy JWT cho auto-OTP flow (dùng bởi RestController).
     * Public wrapper của get_valid_jwt() — xử lý re-login nếu cần.
     *
     * @return string|WP_Error
     */
    public function get_jwt_for_auto_otp( int $user_id ) {
        if ( ! $this->repo->is_jwt_valid( $user_id ) ) {
            // Thử tự re-login bằng password đã lưu
            $password = $this->repo->get_password( $user_id );
            if ( $password === '' ) {
                return new WP_Error( 'dnse_jwt_expired', 'JWT hết hạn, không có password lưu.' );
            }
            $creds    = $this->repo->get_credentials( $user_id );
            $username = $creds['dnse_account_no'] ?? '';
            if ( $username === '' ) {
                return new WP_Error( 'dnse_no_username', 'Không có username DNSE.' );
            }
            $login = $this->api->login( $username, $password );
            if ( is_wp_error( $login ) ) {
                return $login;
            }
            $this->repo->save_jwt_token( $user_id, $login['jwt'], $login['expires_at'], $username );
        }
        return $this->get_valid_jwt( $user_id );
    }

    private function get_valid_jwt( int $user_id ) {
        if ( ! $this->repo->is_jwt_valid( $user_id ) ) {
            return new WP_Error( 'dnse_not_connected',
                'Chưa kết nối DNSE hoặc token đã hết hạn. Vui lòng đăng nhập lại.'
            );
        }

        $creds = $this->repo->get_credentials( $user_id );
        $jwt   = $creds['jwt_token'] ?? '';

        if ( $jwt === '' ) {
            return new WP_Error( 'dnse_token_empty', 'Không đọc được token. Vui lòng kết nối lại.' );
        }

        return $jwt;
    }

    /**
     * Normalize sub_accounts từ nhiều format DNSE trả về thành array thẳng.
     */
    private function normalize_sub_accounts( array $data ): array {
        if ( empty( $data ) ) return [];

        // Dạng {"accounts":[...], "default":{...}} — lấy key "accounts"
        if ( isset( $data['accounts'] ) && is_array( $data['accounts'] ) ) {
            return array_values( array_filter( $data['accounts'], static function( $a ) {
                return is_array( $a ) && ! empty( $a['id'] );
            } ) );
        }

        // Dạng sequential array [{...},{...}]
        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            return array_values( array_filter( $data, static function( $a ) {
                return is_array( $a ) && ! empty( $a['id'] );
            } ) );
        }

        // Dạng associative {"default":{...}} → lấy values có id
        $result = [];
        foreach ( $data as $val ) {
            if ( is_array( $val ) && ! empty( $val['id'] ) ) {
                $result[] = $val;
            }
        }
        // Deduplicate
        $seen = [];
        $unique = [];
        foreach ( $result as $a ) {
            $id = (string) ( $a['id'] ?? '' );
            if ( $id && ! isset( $seen[$id] ) ) {
                $seen[$id] = true;
                $unique[]  = $a;
            }
        }
        return $unique;
    }

    private function detect_account_type( array $acct ): string {
        // DNSE dùng boolean flags thay vì productType string
        if ( ! empty( $acct['marginAccount'] ) ) {
            return 'margin';
        }

        // Fallback: productType string (nếu có)
        $type_raw = strtolower( (string) (
            $acct['productType'] ?? $acct['type'] ?? ''
        ) );
        if ( $type_raw !== '' && ( strpos( $type_raw, 'margin' ) !== false || strpos( $type_raw, 'ky_quy' ) !== false ) ) {
            return 'margin';
        }

        return 'spot';
    }

    // =========================================================================
    // SMART OTP AUTO-RENEW — Phương án B
    // =========================================================================

    /**
     * Lưu SmartOTP secret key vào DB sau khi validate.
     * Chấp nhận cả 2 format:
     *   1. Raw Base32 string: "JBSWY3DPEHPK3PXP"
     *   2. otpauth URI:       "otpauth://totp/DNSE:user@email.com?secret=JBSWY3DPEHPK3PXP&issuer=DNSE"
     *
     * @return true|WP_Error
     */
    /**
     * Trả về trạng thái auto-renew cho frontend (true/false, không lộ secret).
     */
    public function get_smart_otp_secret_status( int $user_id ): bool {
        return $this->repo->get_smart_otp_secret( $user_id ) !== '';
    }

    public function save_smart_otp_secret( int $user_id, string $input ) {
        $input = trim( $input );

        // Parse otpauth:// URI
        if ( strncasecmp( $input, 'otpauth://', 10 ) === 0 ) {
            $query = (string) parse_url( $input, PHP_URL_QUERY );
            parse_str( $query, $params );
            $secret = strtoupper( trim( (string) ( $params['secret'] ?? '' ) ) );
        } else {
            // Raw Base32 — loại bỏ dấu cách nếu user copy có spaces
            $secret = strtoupper( preg_replace( '/\s+/', '', $input ) );
        }

        // Validate: Base32 alphabet + độ dài tối thiểu 16 ký tự (80 bits)
        if ( ! preg_match( '/^[A-Z2-7]{16,}$/', $secret ) ) {
            return new WP_Error(
                'dnse_invalid_secret',
                'SmartOTP secret không hợp lệ. Cần ít nhất 16 ký tự Base32 (A-Z, 2-7). ' .
                'Vui lòng kiểm tra lại secret key hoặc dùng otpauth:// URI.'
            );
        }

        // Kiểm tra user đã kết nối DNSE chưa (cần có jwt)
        if ( ! $this->repo->is_jwt_valid( $user_id ) ) {
            return new WP_Error(
                'dnse_not_connected',
                'Vui lòng kết nối DNSE trước khi thiết lập SmartOTP.'
            );
        }

        // Test TOTP — tính thử 1 mã để xác nhận secret đúng format
        $test_otp = $this->compute_totp( $secret );
        if ( strlen( $test_otp ) !== 6 ) {
            return new WP_Error( 'dnse_totp_compute_failed', 'Không thể tính OTP từ secret này.' );
        }

        $saved = $this->repo->save_smart_otp_secret( $user_id, $secret );
        if ( ! $saved ) {
            return new WP_Error( 'dnse_save_failed', 'Không thể lưu secret vào DB.' );
        }

        error_log( "[LCNI DNSE] SmartOTP secret saved for user {$user_id}" );
        return true;
    }

    /**
     * Xoá SmartOTP secret và tắt auto-renew.
     */
    public function remove_smart_otp_secret( int $user_id ): void {
        $this->repo->clear_smart_otp_secret( $user_id );
        error_log( "[LCNI DNSE] SmartOTP secret removed for user {$user_id}" );
    }

    /**
     * Auto-renew trading token cho user nếu:
     *   1. User có SmartOTP secret (auto_renew_enabled = 1)
     *   2. Trading token sắp hết hạn (< 30 phút còn lại) hoặc đã hết
     *   3. JWT vẫn còn hạn
     *
     * Được gọi từ cron mỗi phút (Module::run_auto_sync).
     * Trả về true = đã renew hoặc chưa cần renew.
     * Trả về WP_Error = có lỗi cần log.
     *
     * @return true|false|WP_Error
     *   true      = renew thành công hoặc token còn đủ hạn
     *   false     = user không có auto-renew → bỏ qua
     *   WP_Error  = lỗi khi renew
     */
    public function auto_renew_trading_token( int $user_id ) {
        // Lấy secret — nếu rỗng thì user không dùng auto-renew
        $secret = $this->repo->get_smart_otp_secret( $user_id );
        if ( $secret === '' ) {
            return false; // bình thường, không phải lỗi
        }

        // Kiểm tra trading token còn đủ hạn chưa (buffer 1 giờ)
        // Buffer 1h (thay vì 30 phút) — xem lý do tại DnseGmailOAuthService::auto_renew_trading_token()
        $creds      = $this->repo->get_credentials( $user_id );
        $expires_at = (int) ( $creds['trading_expires_at'] ?? 0 );
        $buffer     = HOUR_IN_SECONDS;

        if ( $expires_at > time() + $buffer ) {
            return true; // còn đủ hạn, không cần renew
        }

        // Lấy JWT
        $jwt = $this->get_valid_jwt( $user_id );
        if ( is_wp_error( $jwt ) ) {
            return new WP_Error(
                'dnse_auto_renew_jwt_expired',
                "User {$user_id}: JWT hết hạn, cần đăng nhập lại. " . $jwt->get_error_message()
            );
        }

        // Tính TOTP từ secret — thuần PHP, không cần lib ngoài
        $otp = $this->compute_totp( $secret );

        // Gọi DNSE API lấy trading token mới
        $result = $this->api->get_trading_token_by_smart_otp( $jwt, $otp );

        if ( is_wp_error( $result ) ) {
            // Thử lại với OTP của time-step trước (phòng clock skew ±30s)
            $otp_prev = $this->compute_totp( $secret, time() - 30 );
            $result   = $this->api->get_trading_token_by_smart_otp( $jwt, $otp_prev );
        }

        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                'dnse_auto_renew_failed',
                "User {$user_id}: " . $result->get_error_message()
            );
        }

        $this->repo->save_trading_token(
            $user_id,
            $result['trading_token'],
            $result['expires_at']
        );

        $new_exp = date( 'H:i d/m', $result['expires_at'] );
        error_log( "[LCNI DNSE] Auto-renewed trading token for user {$user_id}, expires {$new_exp}" );

        return true;
    }

    /**
     * Tính TOTP (RFC 6238) — thuần PHP, không cần thư viện ngoài.
     * Cùng thuật toán với Google Authenticator / EntradeX SmartOTP.
     *
     * @param string $base32_secret  Secret key dạng Base32 (A-Z, 2-7)
     * @param int|null $at_time      Unix timestamp (null = now)
     * @return string  6-digit OTP string (có leading zero nếu cần)
     */
    private function compute_totp( string $base32_secret, ?int $at_time = null ): string {
        // ── Bước 1: Base32 decode ─────────────────────────────────────────
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret   = '';
        $buffer   = 0;
        $bits     = 0;

        foreach ( str_split( strtoupper( $base32_secret ) ) as $char ) {
            $val = strpos( $alphabet, $char );
            if ( $val === false ) continue; // bỏ qua ký tự padding/không hợp lệ

            $buffer = ( $buffer << 5 ) | $val;
            $bits  += 5;

            if ( $bits >= 8 ) {
                $secret .= chr( ( $buffer >> ( $bits - 8 ) ) & 0xFF );
                $bits   -= 8;
            }
        }

        // ── Bước 2: Tính time counter (30-second window) ──────────────────
        $timestamp = $at_time ?? time();
        $counter   = (int) floor( $timestamp / 30 );

        // Pack thành 8-byte big-endian (uint64)
        // PHP pack 'J' = unsigned 64-bit big-endian (PHP ≥ 5.6.3)
        // Fallback cho 32-bit: dùng 2x 'N'
        if ( PHP_INT_SIZE >= 8 ) {
            $time_bytes = pack( 'J', $counter );
        } else {
            $time_bytes = pack( 'NN', 0, $counter ); // upper 32-bit = 0
        }

        // ── Bước 3: HMAC-SHA1 ─────────────────────────────────────────────
        $hash = hash_hmac( 'sha1', $time_bytes, $secret, true );

        // ── Bước 4: Dynamic truncation ────────────────────────────────────
        $offset = ord( $hash[19] ) & 0x0F;
        $code   = (
            ( ( ord( $hash[ $offset ]     ) & 0x7F ) << 24 ) |
            ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 ) |
            ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) <<  8 ) |
              ( ord( $hash[ $offset + 3 ] ) & 0xFF )
        ) % 1_000_000;

        return str_pad( (string) $code, 6, '0', STR_PAD_LEFT );
    }
}
