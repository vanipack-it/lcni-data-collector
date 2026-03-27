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

    // DNSE Lightspeed API — tất cả endpoint dùng services.entrade.com.vn
    // JWT từ domain này mới dùng được cho email-otp và trading-token
    const BASE_URL_OLD = 'https://services.entrade.com.vn';
    const BASE_URL_NEW = 'https://services.entrade.com.vn'; // same, keep for compat
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
        // Theo docs DNSE: GET services.entrade.com.vn/dnse-auth-service/api/email-otp
        // Authorization: Bearer <jwt> + Content-Type: application/json
        // Response body rỗng, HTTP 200 = thành công
        $res = $this->get_from( self::BASE_URL, '/dnse-auth-service/api/email-otp', $jwt );
        if ( is_wp_error( $res ) ) return $res;
        return true;
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

        // Theo docs: POST services.entrade.com.vn/dnse-order-service/trading-token
        // Header: smart-otp: <otp>, body rỗng --data ''
        $res = $this->post_to( self::BASE_URL, '/dnse-order-service/trading-token', [], $jwt, $headers );
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

        // Theo docs: POST services.entrade.com.vn/dnse-order-service/trading-token
        // Header: otp: <otp_from_email>, body rỗng --data ''
        $res = $this->post_to( self::BASE_URL, '/dnse-order-service/trading-token', [], $jwt, $headers );
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

        // Gửi body cho POST/PUT — không gửi cho GET/DELETE
        if ( $body !== null && in_array( $method, ['POST', 'PUT', 'PATCH'], true ) && ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
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
     * Docs: POST /dnse-order-service/v2/orders
     * Header: Authorization + trading-token
     * Body: { symbol, side, orderType, price, quantity, accountNo, loanPackageId }
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
        $body = [
            'symbol'        => strtoupper( $symbol ),
            'side'          => strtolower( $side ),
            'orderType'     => strtoupper( $order_type ),
            'price'         => $price,
            'quantity'      => $quantity,
            'accountNo'     => $account_no,
            'loanPackageId' => $loan_package_id,
        ];

        // Price = 0 cho lệnh thị trường MP/ATO/ATC
        if ( in_array( strtoupper( $order_type ), [ 'MP', 'ATO', 'ATC' ], true ) ) {
            unset( $body['price'] );
        }

        $res = $this->request(
            'POST',
            self::BASE_URL . '/dnse-order-service/v2/orders',
            $body,
            $jwt,
            [ 'trading-token' => $trading_token ]
        );

        if ( is_wp_error( $res ) ) return $res;

        // Validate response
        if ( empty( $res['orderId'] ) && empty( $res['id'] ) ) {
            error_log( '[LCNI DNSE] place_order unexpected response: ' . wp_json_encode( $res ) );
        }

        return $res;
    }

    /**
     * Hủy lệnh.
     * Docs: DELETE /dnse-order-service/v2/orders/{orderId}
     * Header: Authorization + trading-token
     */
    public function cancel_order(
        string $jwt,
        string $trading_token,
        string $order_id,
        string $account_no
    ) {
        return $this->request(
            'DELETE',
            self::BASE_URL . '/dnse-order-service/v2/orders/' . urlencode( $order_id ) . '?accountNo=' . urlencode( $account_no ),
            null,
            $jwt,
            [ 'trading-token' => $trading_token ]
        );
    }

    /**
     * Lấy danh sách gói vay (cho lệnh margin).
     * Docs: GET /dnse-order-service/accounts/{no}/loan-packages
     */
    public function get_loan_packages( string $jwt, string $account_no, string $type = 'spot' ) {
        $res = $this->get(
            "/dnse-order-service/accounts/{$account_no}/loan-packages?productType={$type}",
            $jwt
        );
        if ( is_wp_error( $res ) ) return $res;
        return isset( $res['data'] ) ? $res['data'] : ( is_array( $res ) ? $res : [] );
    }

}
