<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DnseTradingApiClient
 *
 * HTTP client cho DNSE Lightspeed Trading API.
 * Tài liệu: https://developers.dnse.com.vn/docs/guide/trading-api/
 *
 * Endpoints được dùng:
 *   POST /dnse-auth-service/api/login              — Đăng nhập, lấy jwt-token
 *   GET  /dnse-auth-service/api/email-otp          — Gửi email OTP
 *   POST /dnse-order-service/trading-token         — Lấy trading-token từ Smart OTP
 *   POST /dnse-order-service/trading-token-by-email— Lấy trading-token từ Email OTP
 *   GET  /dnse-order-service/accounts              — Danh sách tiểu khoản
 *   GET  /dnse-order-service/accounts/{no}/balance — Số dư tiểu khoản
 *   GET  /dnse-order-service/accounts/{no}/trade-capacities — Sức mua
 *   GET  /dnse-order-service/v2/orders             — Danh sách lệnh
 *   GET  /dnse-order-service/positions             — Danh mục nắm giữ
 */
class LCNI_DnseTradingApiClient {

    // DNSE Lightspeed API
    // AUTH  : services.entrade.com.vn  (login, email-otp, trading-token)
    // ORDERS: api.dnse.com.vn          (đặt lệnh, sổ lệnh, positions, accounts)
    // Tài liệu chính thức v1: https://hdsd.dnse.com.vn/.../4.3.-dat-lenh
    const BASE_URL_AUTH  = 'https://services.entrade.com.vn';
    const BASE_URL_ORDER = 'https://api.dnse.com.vn';
    // Giữ BASE_URL trỏ đến auth để các helper login/otp không đổi
    const BASE_URL_OLD = 'https://services.entrade.com.vn';
    const BASE_URL_NEW = 'https://services.entrade.com.vn';
    const BASE_URL     = 'https://services.entrade.com.vn';
    const TIMEOUT    = 15;

    // =========================================================================
    // AUTHENTICATION
    // =========================================================================

    /**
     * Đăng nhập DNSE, lấy jwt-token.
     * QUAN TRỌNG: password KHÔNG được lưu vào DB sau khi gọi xong.
     *
     * @return array|WP_Error  ['jwt' => string, 'expires_at' => int]
     */
    public function login( string $username, string $password ) {
        // Login: POST /dnse-auth-service/login
        // Body: { username, password }
        // Response: { jwt: "..." } — JWT dùng cho tất cả call tiếp theo

        $body = [ 'username' => $username, 'password' => $password ];
        $res = $this->post_to( self::BASE_URL, '/dnse-auth-service/login', $body );

        if ( is_wp_error( $res ) ) return $res;

        // Response field: 'token' (mới) hoặc 'jwt' (cũ)
        $jwt = (string) ( $res['token'] ?? $res['jwt'] ?? $res['access_token'] ?? '' );
        if ( $jwt === '' ) {
            return new WP_Error( 'dnse_login_failed',
                'Đăng nhập thất bại. Kiểm tra lại tài khoản/mật khẩu DNSE.'
            );
        }

        // JWT DNSE hết hạn 8h (domain mới) — decode để lấy exp chính xác
        $expires_at = $this->decode_jwt_exp( $jwt ) ?: ( time() + 8 * HOUR_IN_SECONDS );

        return [ 'jwt' => $jwt, 'expires_at' => $expires_at ];
    }

    /**
     * Yêu cầu gửi Email OTP về hộp thư đăng ký.
     */
    public function request_email_otp( string $jwt ) {
        // Thử lần lượt các endpoint có thể có — DNSE thay đổi path giữa các version
        $endpoints = [
            [ self::BASE_URL, '/dnse-auth-service/api/email-otp' ],  // docs cũ
            [ self::BASE_URL, '/dnse-auth-service/email-otp' ],       // không có /api/
            [ self::BASE_URL_ORDER, '/order-service/email-otp' ],     // domain mới
        ];

        $last_error = null;
        foreach ( $endpoints as [ $base, $path ] ) {
            $res = $this->get_from( $base, $path, $jwt );
            if ( ! is_wp_error( $res ) ) {
                error_log( '[LCNI DNSE] request_email_otp OK via ' . $base . $path );
                return true;
            }
            $msg = $res->get_error_message();
            error_log( '[LCNI DNSE] request_email_otp FAIL ' . $base . $path . ' — ' . $msg );
            // Chỉ thử tiếp nếu lỗi 404/405 (endpoint không tồn tại), dừng nếu 400/500 (endpoint đúng nhưng lỗi logic)
            if ( strpos( $msg, '[404]' ) === false && strpos( $msg, '[405]' ) === false ) {
                return $res; // 400/500 = endpoint đúng nhưng DNSE báo lỗi — trả luôn
            }
            $last_error = $res;
        }
        return $last_error;
    }

    /**
     * Xác thực Smart OTP, lấy trading-token.
     * trading-token hết hạn sau 8h.
     *
     * @return array|WP_Error  ['trading_token' => string, 'expires_at' => int]
     */
    public function get_trading_token_by_smart_otp( string $jwt, string $smart_otp ) {
        // Smart OTP: truyền vào HEADER 'smart-otp', body để rỗng
        // Domain cũ: POST /dnse-order-service/trading-token
        // Domain mới: POST /order-service/trading-token (thử cả 2)
        $headers = [ 'smart-otp' => $smart_otp ];

        // Endpoint mới: POST api.dnse.com.vn/order-service/trading-token
        // Fallback cũ: POST services.entrade.com.vn/dnse-order-service/trading-token
        $res = $this->post_to( self::BASE_URL_ORDER, '/order-service/trading-token', [], $jwt, $headers );
        if ( is_wp_error( $res ) ) {
            // Fallback về endpoint cũ
            $res = $this->post_to( self::BASE_URL, '/dnse-order-service/trading-token', [], $jwt, $headers );
        }
        if ( is_wp_error( $res ) ) return $res;

        $token = (string) ( $res['tradingToken'] ?? $res['trading_token'] ?? $res['token'] ?? '' );
        if ( $token === '' ) {
            return new WP_Error( 'dnse_otp_failed', 'Xác thực Smart OTP thất bại. Kiểm tra mã OTP từ app EntradeX.' );
        }

        return [
            'trading_token' => $token,
            'expires_at'    => time() + ( 8 * HOUR_IN_SECONDS ),
        ];
    }

    /**
     * Xác thực Email OTP, lấy trading-token.
     */
    public function get_trading_token_by_email_otp( string $jwt, string $email_otp ) {
        // Email OTP: truyền vào HEADER 'otp', body để rỗng (giống Smart OTP)
        // Tài liệu DNSE: --header 'otp: <otp_from_email>' --data ''
        $headers = [ 'otp' => $email_otp ];

        // Endpoint mới: POST api.dnse.com.vn/order-service/trading-token
        // Fallback cũ: POST services.entrade.com.vn/dnse-order-service/trading-token
        $res = $this->post_to( self::BASE_URL_ORDER, '/order-service/trading-token', [], $jwt, $headers );
        if ( is_wp_error( $res ) ) {
            $res = $this->post_to( self::BASE_URL, '/dnse-order-service/trading-token', [], $jwt, $headers );
        }

        // Log raw để debug khi OTP thất bại
        error_log( '[LCNI DNSE] get_trading_token_by_email_otp otp=' . $email_otp . ' result=' . ( is_wp_error( $res ) ? $res->get_error_message() : json_encode( $res ) ) );

        if ( is_wp_error( $res ) ) return $res;

        $token = (string) ( $res['tradingToken'] ?? $res['trading_token'] ?? $res['token'] ?? '' );
        if ( $token === '' ) {
            return new WP_Error( 'dnse_otp_failed', 'Xác thực Email OTP thất bại. Kiểm tra hộp thư và nhập lại mã OTP.' );
        }

        return [
            'trading_token' => $token,
            'expires_at'    => time() + ( 8 * HOUR_IN_SECONDS ),
        ];
    }

    // =========================================================================
    // ACCOUNT INFO (chỉ cần jwt-token)
    // =========================================================================

    /**
     * Lấy danh sách tiểu khoản.
     *
     * @return array|WP_Error  [['id' => ..., 'investorAccountNo' => ..., ...], ...]
     */
    public function get_sub_accounts( string $jwt ) {
        // Domain mới: /order-service/accounts
        // Domain cũ:  /dnse-order-service/accounts
        $res = $this->get( '/order-service/accounts', $jwt );
        if ( is_wp_error( $res ) ) {
            $res = $this->get( '/dnse-order-service/accounts', $jwt );
        }
        if ( is_wp_error( $res ) ) return $res;

        // DNSE API trả về nhiều dạng:
        // 1. {"default":{...}, "accounts":[...]}  ← dạng thực tế
        // 2. {"data": [...]}
        // 3. [{...}, {...}]  ← array thẳng

        // Ưu tiên key "accounts" nếu có
        if ( isset( $res['accounts'] ) && is_array( $res['accounts'] ) ) {
            $raw_list = $res['accounts'];
        } elseif ( isset( $res['data'] ) && is_array( $res['data'] ) ) {
            $raw_list = $res['data'];
        } elseif ( isset( $res[0] ) ) {
            // Sequential array
            $raw_list = $res;
        } else {
            // Associative với key "default" hoặc tương tự → lấy tất cả values là array có 'id'
            $raw_list = [];
            foreach ( $res as $val ) {
                if ( is_array( $val ) && ! empty( $val['id'] ) ) {
                    $raw_list[] = $val;
                }
            }
        }

        // Deduplicate theo id
        $seen   = [];
        $unique = [];
        foreach ( $raw_list as $acct ) {
            if ( ! is_array( $acct ) ) continue;
            $id = (string) ( $acct['id'] ?? '' );
            if ( $id !== '' && ! isset( $seen[ $id ] ) ) {
                $seen[ $id ] = true;
                $unique[]    = $acct;
            }
        }

        return $unique;
    }

    /**
     * Lấy số dư tiểu khoản (balance, margin info).
     */
    public function get_account_balance( string $jwt, string $account_no, string $type = 'spot' ) {
        $endpoint = $type === 'margin'
            ? "/dnse-order-service/accounts/{$account_no}/margin-balance"
            : "/dnse-order-service/accounts/{$account_no}/balance";

        $res = $this->get( $endpoint, $jwt );
        if ( is_wp_error( $res ) ) return $res;

        return isset( $res['data'] ) ? $res['data'] : $res;
    }

    /**
     * Lấy sức mua (trade capacity) của tiểu khoản.
     * Trả về buying power, max value, margin levels, v.v.
     */
    public function get_trade_capacities( string $jwt, string $account_no, string $type = 'spot', string $symbol = '', float $price = 0 ) {
        $params = [ 'accountNo' => $account_no, 'productType' => $type ];
        if ( $symbol !== '' ) $params['symbol'] = strtoupper( $symbol );
        if ( $price > 0 )     $params['price']  = $price;

        $res = $this->get(
            '/dnse-order-service/accounts/' . $account_no . '/trade-capacities?' . http_build_query( $params ),
            $jwt
        );
        if ( is_wp_error( $res ) ) return $res;

        return isset( $res['data'] ) ? $res['data'] : $res;
    }

    // =========================================================================
    // POSITIONS (danh mục đang nắm giữ)
    // =========================================================================

    /**
     * Lấy danh sách vị thế (positions/deals) của tiểu khoản.
     */
    public function get_positions( string $jwt, string $account_no, string $type = 'spot' ) {
        $endpoint = $type === 'margin'
            ? "/dnse-order-service/accounts/{$account_no}/deals"
            : "/dnse-order-service/positions";

        $params = [ 'accountNo' => $account_no ];
        $res = $this->get( $endpoint . '?' . http_build_query( $params ), $jwt );
        if ( is_wp_error( $res ) ) return $res;

        if ( isset( $res['data'] ) && is_array( $res['data'] ) ) {
            return $res['data'];
        }
        return is_array( $res ) ? $res : [];
    }

    // =========================================================================
    // ORDERS (sổ lệnh)
    // =========================================================================

    /**
     * Lấy danh sách lệnh của tiểu khoản.
     */
    public function get_orders( string $jwt, string $account_no, string $type = 'spot', string $date = '' ) {
        if ( $date === '' ) $date = current_time( 'Y-m-d' );

        $params = [
            'accountNo'   => $account_no,
            'productType' => $type,
            'fromDate'    => $date,
            'toDate'      => $date,
        ];

        $res = $this->get(
            '/dnse-order-service/v2/orders?' . http_build_query( $params ),
            $jwt
        );
        if ( is_wp_error( $res ) ) return $res;

        if ( isset( $res['data'] ) && is_array( $res['data'] ) ) {
            return $res['data'];
        }
        return is_array( $res ) ? $res : [];
    }

    // =========================================================================
    // HTTP HELPERS
    // =========================================================================

    private function get( string $endpoint, string $jwt = '', array $extra_headers = [] ) {
        return $this->request( 'GET', self::BASE_URL . $endpoint, null, $jwt, $extra_headers );
    }

    private function post( string $endpoint, array $body = [], string $jwt = '', array $extra_headers = [] ) {
        return $this->request( 'POST', self::BASE_URL . $endpoint, $body, $jwt, $extra_headers );
    }

    /**
     * GET đến base URL cụ thể (dùng khi thử nhiều domain)
     */
    private function get_from( string $base, string $endpoint, string $jwt = '' ) {
        return $this->request( 'GET', $base . $endpoint, null, $jwt, [] );
    }

    /**
     * POST đến URL đầy đủ (dùng khi thử nhiều base URL khác nhau)
     */
    private function post_to( string $base, string $endpoint, array $body, string $jwt = '', array $extra_headers = [] ) {
        return $this->request( 'POST', $base . $endpoint, $body, $jwt, $extra_headers );
    }

    private function request( string $method, string $url, ?array $body, string $jwt, array $extra_headers ) {
        try {
            return $this->_do_request( $method, $url, $body, $jwt, $extra_headers );
        } catch ( \Throwable $e ) {
            error_log( '[LCNI DNSE] request() uncaught exception: ' . $e->getMessage() . ' at ' . $url );
            return new WP_Error( 'dnse_exception', 'Lỗi hệ thống DNSE: ' . $e->getMessage() );
        }
    }

    private function _do_request( string $method, string $url, ?array $body, string $jwt, array $extra_headers ) {
        $method = strtoupper( $method );

        // DNSE yêu cầu Content-Type: application/json cho tất cả request kể cả GET
        // (theo docs DNSE: --header 'Content-Type: application/json' trên cả GET email-otp)
        $headers = array_merge( [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ], $extra_headers );

        if ( $jwt !== '' ) {
            $headers['Authorization'] = 'Bearer ' . $jwt;
        }

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
        ];

        // Gửi body cho POST/PUT — body rỗng [] vẫn phải gửi '' để có Content-Length: 0
        // DNSE endpoint /dnse-order-service/trading-token yêu cầu --data '' (body rỗng nhưng có header)
        // Nếu bỏ qua body hoàn toàn, WP không set Content-Length → server trả 400
        if ( in_array( $method, ['POST', 'PUT', 'PATCH'], true ) ) {
            $args['body'] = ( $body !== null && ! empty( $body ) )
                ? wp_json_encode( $body )
                : '';
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dnse_http_error',
                'Lỗi kết nối DNSE: ' . $response->get_error_message()
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );

        if ( $status === 401 ) {
            return new WP_Error( 'dnse_unauthorized',
                'Token hết hạn hoặc không hợp lệ. Vui lòng đăng nhập lại.'
            );
        }

        if ( $status >= 400 ) {
            $decoded = json_decode( $raw, true );
            $msg = (string) ( $decoded['message'] ?? $decoded['error'] ?? $decoded['errorMessage'] ?? "HTTP {$status}" );
            // Log URL để debug
            error_log( '[LCNI DNSE] API Error ' . $status . ' at ' . $url . ' — ' . $msg );
            return new WP_Error( 'dnse_api_error', "DNSE API lỗi [{$status}]: {$msg}" );
        }

        // Body rỗng hoặc non-JSON = thành công với một số endpoint (email-otp, trading-token)
        if ( $raw === '' ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            // Non-JSON 2xx = coi là thành công (DNSE đôi khi trả plain text)
            error_log( '[LCNI DNSE] Non-JSON 2xx from ' . $url . ': ' . substr( $raw, 0, 100 ) );
            return [];
        }

        return is_array( $decoded ) ? $decoded : [];
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================

    /**
     * Decode JWT payload để lấy exp (không verify signature).
     */
    private function decode_jwt_exp( string $jwt ): int {
        $parts = explode( '.', $jwt );
        if ( count( $parts ) !== 3 ) return 0;

        $payload = json_decode( base64_decode( str_pad(
            strtr( $parts[1], '-_', '+/' ),
            strlen( $parts[1] ) + ( 4 - strlen( $parts[1] ) % 4 ) % 4, '='
        ) ), true );

        return isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;
    }

    // =========================================================================
    // ORDER PLACEMENT — Giai đoạn 2
    // =========================================================================

    /**
     * Đặt lệnh mua/bán.
     *
     * Tài liệu DNSE chính thức (4.3. Đặt lệnh):
     *   POST https://api.dnse.com.vn/order-service/v2/orders
     *   Header: Authorization: Bearer <jwt>, trading-token: <trading_token>
     *   Body fields:
     *     symbol       — Mã CK, uppercase
     *     side         — "NB" (Mua) | "NS" (Bán)  ← KHÔNG phải "buy"/"sell"
     *     orderType    — LO | MP | MTL | ATO | ATC | MOK | MAK
     *     price        — Giá, đơn vị ĐỒNG (VNĐ đầy đủ, ví dụ 26600) ← KHÔNG phải DB format
     *     quantity     — Khối lượng
     *     loanPackageId— Mã gói vay (0 = tiền mặt)
     *     accountNo    — Mã tiểu khoản
     *
     * @param string $side  'buy' hoặc 'sell' (controller truyền vào) — sẽ convert sang NB/NS
     * @param float  $price Giá DB format (nghìn VNĐ, ví dụ 26.6) — sẽ convert sang full VNĐ (26600)
     */
    public function place_order(
        string $jwt,
        string $trading_token,
        string $account_no,
        string $symbol,
        string $side,
        string $order_type,
        float  $price,
        int    $quantity,
        int    $loan_package_id = 0
    ) {
        // FIX 1: Convert side "buy"/"sell" → "NB"/"NS" theo yêu cầu DNSE API
        $side_upper = strtolower( $side );
        $dnse_side  = ( $side_upper === 'sell' ) ? 'NS' : 'NB';

        // FIX 2: Convert price từ DB format (nghìn VNĐ) sang full VNĐ (đồng)
        // DnseOrderService nhận price theo DB format (ví dụ 26.6 = 26,600 VNĐ)
        // DNSE API yêu cầu price theo full VNĐ (ví dụ 26600)
        $market_types = [ 'MP', 'MTL', 'ATO', 'ATC', 'MOK', 'MAK', 'PM' ];
        $is_market    = in_array( strtoupper( $order_type ), $market_types, true );
        $price_vnd    = $is_market ? null : (int) round( $price * 1000 );

        $body = [
            'symbol'        => strtoupper( $symbol ),
            'side'          => $dnse_side,
            'orderType'     => strtoupper( $order_type ),
            'quantity'      => $quantity,
            'accountNo'     => $account_no,
            'loanPackageId' => $loan_package_id,
        ];

        // Chỉ thêm price khi không phải lệnh thị trường
        if ( ! $is_market && $price_vnd !== null ) {
            $body['price'] = $price_vnd;
        }

        // FIX 3: Endpoint đúng theo tài liệu v1: api.dnse.com.vn/order-service/v2/orders
        $res = $this->request(
            'POST',
            self::BASE_URL_ORDER . '/order-service/v2/orders',
            $body,
            $jwt,
            [ 'trading-token' => $trading_token ]
        );

        if ( is_wp_error( $res ) ) return $res;

        // Log để debug — response field là 'id' theo tài liệu DNSE
        if ( empty( $res['id'] ) ) {
            error_log( '[LCNI DNSE] place_order unexpected response: ' . wp_json_encode( $res ) );
        }

        return $res;
    }

    /**
     * Hủy lệnh.
     * Tài liệu DNSE (4.5. Hủy lệnh):
     *   DELETE https://api.dnse.com.vn/order-service/v2/orders/{orderId}?accountNo=...
     */
    public function cancel_order(
        string $jwt,
        string $trading_token,
        string $order_id,
        string $account_no
    ) {
        return $this->request(
            'DELETE',
            self::BASE_URL_ORDER . '/order-service/v2/orders/' . urlencode( $order_id ) . '?accountNo=' . urlencode( $account_no ),
            null,
            $jwt,
            [ 'trading-token' => $trading_token ]
        );
    }

    /**
     * Lấy danh sách gói vay của tiểu khoản.
     * Tài liệu DNSE v1 (4.1): GET https://api.dnse.com.vn/order-service/v2/accounts/{accountNo}/loan-packages
     * Response: { loanPackages: [{id, name, type('N'|'M'), ...}] }
     * type=N → Non-Margin (tiền mặt), type=M → Margin
     */
    public function get_loan_packages( string $jwt, string $account_no, string $type = 'spot' ) {
        // Endpoint đúng theo tài liệu v1 (dùng BASE_URL_ORDER = api.dnse.com.vn)
        $res = $this->request(
            'GET',
            self::BASE_URL_ORDER . "/order-service/v2/accounts/{$account_no}/loan-packages",
            null,
            $jwt,
            []
        );
        if ( is_wp_error( $res ) ) return $res;

        // Response: { loanPackages: [...] } hoặc array thẳng
        $packages = $res['loanPackages'] ?? ( isset( $res[0] ) ? $res : ( $res['data'] ?? [] ) );
        return is_array( $packages ) ? $packages : [];
    }

}
