<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DnseTradingRepository
 *
 * Quản lý DB cho module DNSE Trading:
 *   - lcni_dnse_credentials : JWT token + trading token theo user (mã hoá)
 *   - lcni_dnse_accounts    : cache thông tin tiểu khoản
 *   - lcni_dnse_positions   : cache danh mục đang nắm
 *   - lcni_dnse_orders      : cache sổ lệnh
 *
 * SECURITY: Không lưu password DNSE. Chỉ lưu JWT token (đọc) và
 * trading token (giao dịch, hết hạn 8h). Mã hoá bằng AUTH_KEY.
 */
class LCNI_DnseTradingRepository {

    /** @var wpdb */
    private $wpdb;

    private $tbl_credentials;
    private $tbl_accounts;
    private $tbl_positions;
    private $tbl_orders;

    public function __construct( ?wpdb $wpdb = null ) {
        if ( $wpdb !== null ) {
            $this->wpdb = $wpdb;
        } else {
            global $wpdb;
            $this->wpdb = $wpdb;
        }
        $p = $this->wpdb->prefix;
        $this->tbl_credentials = $p . 'lcni_dnse_credentials';
        $this->tbl_accounts    = $p . 'lcni_dnse_accounts';
        $this->tbl_positions   = $p . 'lcni_dnse_positions';
        $this->tbl_orders      = $p . 'lcni_dnse_orders';
    }

    // =========================================================================
    // SCHEMA
    // =========================================================================

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $prev = $wpdb->suppress_errors( true );

        dbDelta( "CREATE TABLE {$p}lcni_dnse_credentials (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            dnse_account_no VARCHAR(30) NOT NULL DEFAULT '',
            jwt_token_enc TEXT DEFAULT NULL,
            jwt_expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            trading_token_enc TEXT DEFAULT NULL,
            trading_expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            password_enc TEXT DEFAULT NULL,
            permissions VARCHAR(200) NOT NULL DEFAULT '',
            sub_accounts_json LONGTEXT DEFAULT NULL,
            connected_at DATETIME DEFAULT NULL,
            last_sync_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user (user_id),
            KEY idx_jwt_expires (jwt_expires_at),
            KEY idx_trading_expires (trading_expires_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$p}lcni_dnse_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            account_no VARCHAR(30) NOT NULL,
            account_type VARCHAR(20) NOT NULL DEFAULT 'spot',
            account_type_name VARCHAR(100) NOT NULL DEFAULT '',
            balance_json LONGTEXT DEFAULT NULL,
            trade_capacity_json LONGTEXT DEFAULT NULL,
            synced_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_acct (user_id, account_no),
            KEY idx_user (user_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$p}lcni_dnse_positions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            account_no VARCHAR(30) NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            account_type VARCHAR(20) NOT NULL DEFAULT 'spot',
            quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            available_quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            avg_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            current_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            market_value DECIMAL(20,2) NOT NULL DEFAULT 0,
            unrealized_pnl DECIMAL(20,2) NOT NULL DEFAULT 0,
            unrealized_pnl_pct DECIMAL(10,4) NOT NULL DEFAULT 0,
            raw_json LONGTEXT DEFAULT NULL,
            synced_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_acct_sym (user_id, account_no, symbol),
            KEY idx_user (user_id),
            KEY idx_symbol (symbol)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$p}lcni_dnse_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            account_no VARCHAR(30) NOT NULL,
            account_type VARCHAR(20) NOT NULL DEFAULT 'spot',
            dnse_order_id VARCHAR(50) NOT NULL DEFAULT '',
            symbol VARCHAR(20) NOT NULL,
            side VARCHAR(10) NOT NULL DEFAULT '',
            order_type VARCHAR(10) NOT NULL DEFAULT 'LO',
            price DECIMAL(15,4) NOT NULL DEFAULT 0,
            quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            filled_quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT '',
            raw_json LONGTEXT DEFAULT NULL,
            order_date DATE DEFAULT NULL,
            synced_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_dnse_id (user_id, dnse_order_id),
            KEY idx_user_acct (user_id, account_no),
            KEY idx_symbol (symbol),
            KEY idx_status (status)
        ) {$charset};" );

        $wpdb->suppress_errors( $prev );
    }

    public static function maybe_create_tables(): void {
        if ( ! get_transient( 'lcni_dnse_schema_v1' ) ) {
            self::create_tables();
            set_transient( 'lcni_dnse_schema_v1', 1, 10 * MINUTE_IN_SECONDS );
        }
        // Migration: thêm cột account_type_name nếu chưa có
        global $wpdb;
        $tbl = $wpdb->prefix . 'lcni_dnse_accounts';
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$tbl} LIKE 'account_type_name'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN account_type_name VARCHAR(100) NOT NULL DEFAULT '' AFTER account_type" );
        }
    }

    // =========================================================================
    // CREDENTIALS
    // =========================================================================

    public function get_credentials( int $user_id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tbl_credentials} WHERE user_id = %d",
                $user_id
            ), ARRAY_A
        );
        if ( ! $row ) return null;

        // Giải mã tokens
        if ( ! empty( $row['jwt_token_enc'] ) ) {
            $row['jwt_token'] = $this->decrypt( $row['jwt_token_enc'] );
        }
        if ( ! empty( $row['trading_token_enc'] ) ) {
            $row['trading_token'] = $this->decrypt( $row['trading_token_enc'] );
        }

        return $row;
    }

    public function save_jwt_token( int $user_id, string $jwt_token, int $expires_at, string $account_no = '' ): bool {
        $enc = $this->encrypt( $jwt_token );
        if ( $enc === false ) return false;

        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->tbl_credentials} WHERE user_id = %d", $user_id
            )
        );

        if ( $existing ) {
            $update = [
                'jwt_token_enc'  => $enc,
                'jwt_expires_at' => $expires_at,
                'connected_at'   => current_time( 'mysql' ),
            ];
            if ( $account_no !== '' ) {
                $update['dnse_account_no'] = $account_no;
            }
            return $this->wpdb->update( $this->tbl_credentials, $update, ['user_id' => $user_id] ) !== false;
        }

        return $this->wpdb->insert( $this->tbl_credentials, [
            'user_id'        => $user_id,
            'dnse_account_no'=> $account_no,
            'jwt_token_enc'  => $enc,
            'jwt_expires_at' => $expires_at,
            'connected_at'   => current_time( 'mysql' ),
        ] ) !== false;
    }

    public function save_trading_token( int $user_id, string $trading_token, int $expires_at ): bool {
        $enc = $this->encrypt( $trading_token );
        if ( $enc === false ) return false;

        return $this->wpdb->update( $this->tbl_credentials, [
            'trading_token_enc'  => $enc,
            'trading_expires_at' => $expires_at,
        ], ['user_id' => $user_id] ) !== false;
    }

    public function save_sub_accounts( int $user_id, array $sub_accounts ): bool {
        return $this->wpdb->update( $this->tbl_credentials, [
            'sub_accounts_json' => wp_json_encode( $sub_accounts, JSON_UNESCAPED_UNICODE ),
        ], ['user_id' => $user_id] ) !== false;
    }

    public function update_last_sync( int $user_id ): void {
        $this->wpdb->update( $this->tbl_credentials, [
            'last_sync_at' => current_time( 'mysql' ),
        ], ['user_id' => $user_id] );
    }

    public function revoke_credentials( int $user_id ): bool {
        return $this->wpdb->update( $this->tbl_credentials, [
            'jwt_token_enc'      => null,
            'jwt_expires_at'     => 0,
            'trading_token_enc'  => null,
            'trading_expires_at' => 0,
            'sub_accounts_json'  => null,
            'connected_at'       => null,
        ], ['user_id' => $user_id] ) !== false;
    }

    /**
     * Lấy user_id của tất cả users có JWT còn hạn
     * (để cron kiểm tra auto-renew qua Gmail).
     *
     * @return int[]
     */
    public function get_users_with_valid_jwt(): array {
        // Lấy TẤT CẢ users đã kết nối DNSE — bao gồm cả user có JWT hết hạn
        // nhưng có password lưu (cần auto re-login).
        // Trước đây chỉ lấy jwt_expires_at > now → bỏ sót user cần re-login nhất.
        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT user_id FROM {$this->tbl_credentials}
                 WHERE (jwt_expires_at > %d OR password_enc IS NOT NULL)
                 ORDER BY trading_expires_at ASC
                 LIMIT 100",
                time()
            )
        );
        return array_map( 'intval', $rows ?: [] );
    }

        public function is_jwt_valid( int $user_id ): bool {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT jwt_expires_at FROM {$this->tbl_credentials} WHERE user_id = %d",
                $user_id
            ), ARRAY_A
        );
        if ( ! $row ) return false;
        return (int) $row['jwt_expires_at'] > time() + 60;
    }

    public function is_trading_token_valid( int $user_id ): bool {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT trading_expires_at FROM {$this->tbl_credentials}
                 WHERE user_id = %d",
                $user_id
            ), ARRAY_A
        );
        if ( ! $row ) return false;
        return (int) $row['trading_expires_at'] > time() + 300; // 5 min buffer
    }

    // =========================================================================
    // ACCOUNTS + BALANCE
    // =========================================================================

    public function upsert_account( int $user_id, string $account_no, string $type, array $balance, array $trade_capacity = [], string $type_name = '' ): bool {
        return $this->wpdb->replace( $this->tbl_accounts, [
            'user_id'              => $user_id,
            'account_no'           => $account_no,
            'account_type'         => $type,
            'account_type_name'    => $type_name,
            'balance_json'         => wp_json_encode( $balance, JSON_UNESCAPED_UNICODE ),
            'trade_capacity_json'  => wp_json_encode( $trade_capacity, JSON_UNESCAPED_UNICODE ),
            'synced_at'            => current_time( 'mysql' ),
        ] ) !== false;
    }

    public function get_accounts( int $user_id ): array {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tbl_accounts} WHERE user_id = %d ORDER BY account_no ASC",
                $user_id
            ), ARRAY_A
        ) ?: [];

        foreach ( $rows as &$row ) {
            $row['balance']         = json_decode( (string) ( $row['balance_json'] ?? '' ), true ) ?: [];
            $row['trade_capacity']  = json_decode( (string) ( $row['trade_capacity_json'] ?? '' ), true ) ?: [];
        }
        unset( $row );
        return $rows;
    }

    // =========================================================================
    // POSITIONS
    // =========================================================================

    public function upsert_positions( int $user_id, string $account_no, string $type, array $positions ): void {
        // Xoá positions cũ của tài khoản này trước
        $this->wpdb->delete( $this->tbl_positions, [
            'user_id'    => $user_id,
            'account_no' => $account_no,
        ] );

        foreach ( $positions as $pos ) {
            $qty          = (float) ( $pos['quantity'] ?? $pos['vol'] ?? 0 );
            $avail_qty    = (float) ( $pos['availableQuantity'] ?? $pos['availVol'] ?? $qty );
            $avg_price    = (float) ( $pos['avgPrice'] ?? $pos['avgCost'] ?? 0 );
            $cur_price    = (float) ( $pos['currentPrice'] ?? $pos['closePrice'] ?? 0 );
            $market_value = $qty * $cur_price * 1000; // giá DNSE tính theo nghìn đồng
            $cost_value   = $qty * $avg_price * 1000;
            $pnl          = $market_value - $cost_value;
            $pnl_pct      = $cost_value > 0 ? $pnl / $cost_value * 100 : 0;
            $symbol       = strtoupper( (string) ( $pos['symbol'] ?? $pos['instrumentSymbol'] ?? '' ) );

            if ( $symbol === '' || $qty <= 0 ) continue;

            $this->wpdb->replace( $this->tbl_positions, [
                'user_id'            => $user_id,
                'account_no'         => $account_no,
                'symbol'             => $symbol,
                'account_type'       => $type,
                'quantity'           => $qty,
                'available_quantity' => $avail_qty,
                'avg_price'          => $avg_price,
                'current_price'      => $cur_price,
                'market_value'       => $market_value,
                'unrealized_pnl'     => $pnl,
                'unrealized_pnl_pct' => round( $pnl_pct, 4 ),
                'raw_json'           => wp_json_encode( $pos, JSON_UNESCAPED_UNICODE ),
                'synced_at'          => current_time( 'mysql' ),
            ] );
        }
    }

    public function get_positions( int $user_id, string $account_no = '' ): array {
        if ( $account_no !== '' ) {
            return $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->tbl_positions}
                     WHERE user_id = %d AND account_no = %s
                     ORDER BY symbol ASC",
                    $user_id, $account_no
                ), ARRAY_A
            ) ?: [];
        }

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tbl_positions}
                 WHERE user_id = %d ORDER BY account_no, symbol ASC",
                $user_id
            ), ARRAY_A
        ) ?: [];
    }

    // =========================================================================
    // ORDERS
    // =========================================================================

    public function upsert_orders( int $user_id, string $account_no, string $type, array $orders ): void {
        foreach ( $orders as $ord ) {
            $dnse_id = (string) ( $ord['orderId'] ?? $ord['id'] ?? '' );
            if ( $dnse_id === '' ) continue;

            $this->wpdb->replace( $this->tbl_orders, [
                'user_id'          => $user_id,
                'account_no'       => $account_no,
                'account_type'     => $type,
                'dnse_order_id'    => $dnse_id,
                'symbol'           => strtoupper( (string) ( $ord['symbol'] ?? $ord['instrumentSymbol'] ?? '' ) ),
                'side'             => strtolower( (string) ( $ord['side'] ?? '' ) ),
                'order_type'       => strtoupper( (string) ( $ord['orderType'] ?? 'LO' ) ),
                'price'            => (float) ( $ord['price'] ?? 0 ),
                'quantity'         => (float) ( $ord['quantity'] ?? $ord['vol'] ?? 0 ),
                'filled_quantity'  => (float) ( $ord['filledQuantity'] ?? $ord['matchedVol'] ?? 0 ),
                'status'           => (string) ( $ord['status'] ?? '' ),
                'order_date'       => ! empty( $ord['createdDate'] )
                    ? date( 'Y-m-d', strtotime( $ord['createdDate'] ) )
                    : current_time( 'Y-m-d' ),
                'raw_json'         => wp_json_encode( $ord, JSON_UNESCAPED_UNICODE ),
                'synced_at'        => current_time( 'mysql' ),
            ] );
        }
    }

    public function get_orders( int $user_id, string $account_no = '', int $limit = 50 ): array {
        $where = $this->wpdb->prepare( 'user_id = %d', $user_id );
        if ( $account_no !== '' ) {
            $where .= $this->wpdb->prepare( ' AND account_no = %s', $account_no );
        }

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tbl_orders}
                 WHERE {$where}
                 ORDER BY synced_at DESC LIMIT %d",
                max( 1, min( 200, $limit ) )
            ), ARRAY_A
        ) ?: [];
    }

    // =========================================================================
    // PERMISSIONS — user cho phép hệ thống làm gì với credentials
    // =========================================================================

    /**
     * Các quyền hợp lệ:
     *   perm_sync        — đọc dữ liệu (balance, positions, orders) về DB
     *   perm_auto_renew  — tự động re-login + gia hạn trading token mỗi 8h
     *   perm_trade       — cho phép đặt/hủy lệnh từ plugin
     */
    const VALID_PERMISSIONS = [ 'perm_sync', 'perm_auto_renew', 'perm_trade' ];

    public function save_permissions( int $user_id, array $permissions ): void {
        $clean = array_values( array_intersect( $permissions, self::VALID_PERMISSIONS ) );
        global $wpdb;
        $wpdb->update(
            $this->tbl_credentials,
            [ 'permissions' => implode( ',', $clean ) ],
            [ 'user_id' => $user_id ]
        );
    }

    public function get_permissions( int $user_id ): array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT permissions FROM {$this->tbl_credentials} WHERE user_id = %d",
                $user_id
            ),
            ARRAY_A
        );
        if ( empty( $row['permissions'] ) ) return [];
        return array_filter( explode( ',', $row['permissions'] ) );
    }

    public function has_permission( int $user_id, string $permission ): bool {
        return in_array( $permission, $this->get_permissions( $user_id ), true );
    }

    // =========================================================================
    // PASSWORD — lưu encrypted để auto re-login khi JWT hết hạn
    // =========================================================================

    /**
     * Lưu password DNSE (AES-256 encrypted) vào DB.
     * Gọi trong connect() sau khi login thành công.
     */
    public function save_password( int $user_id, string $password ): void {
        if ( $password === '' ) return;
        global $wpdb;
        $wpdb->update(
            $this->tbl_credentials,
            [ 'password_enc' => $this->encrypt( $password ) ],
            [ 'user_id' => $user_id ]
        );
    }

    /**
     * Lấy password đã decrypt. Trả về '' nếu chưa lưu hoặc lỗi decrypt.
     */
    public function get_password( int $user_id ): string {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT password_enc FROM {$this->tbl_credentials} WHERE user_id = %d",
                $user_id
            ),
            ARRAY_A
        );
        if ( empty( $row['password_enc'] ) ) return '';
        return $this->decrypt( (string) $row['password_enc'] );
    }

    /**
     * Xoá password khỏi DB (gọi khi user disconnect).
     */
    public function delete_password( int $user_id ): void {
        global $wpdb;
        $wpdb->update(
            $this->tbl_credentials,
            [ 'password_enc' => null ],
            [ 'user_id' => $user_id ]
        );
    }

    // =========================================================================
    // ENCRYPTION — dùng AUTH_KEY của WordPress làm secret
    // =========================================================================

    private function get_secret(): string {
        return defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
    }

    private function encrypt( string $plaintext ) {
        if ( $plaintext === '' ) return '';
        $secret = $this->get_secret();
        $key    = hash( 'sha256', $secret, true );
        $iv     = random_bytes( 16 );

        if ( ! function_exists( 'openssl_encrypt' ) ) {
            // Fallback nếu không có OpenSSL: base64 đơn giản (ít bảo mật hơn)
            return base64_encode( $iv . $plaintext );
        }

        $encrypted = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( $encrypted === false ) return false;

        return base64_encode( $iv . $encrypted );
    }

    private function decrypt( string $ciphertext ): string {
        if ( $ciphertext === '' ) return '';
        $secret  = $this->get_secret();
        $key     = hash( 'sha256', $secret, true );
        $decoded = base64_decode( $ciphertext );

        if ( $decoded === false || strlen( $decoded ) < 16 ) return '';

        $iv   = substr( $decoded, 0, 16 );
        $data = substr( $decoded, 16 );

        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return $data; // Fallback
        }

        $decrypted = openssl_decrypt( $data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return $decrypted !== false ? $decrypted : '';
    }
}
