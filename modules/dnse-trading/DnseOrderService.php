<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DnseOrderService — Giai đoạn 2
 *
 * Xử lý đặt lệnh / hủy lệnh qua DNSE API.
 * Kết nối Recommend Signal → Order.
 *
 * SECURITY: Mọi lệnh đều cần trading-token còn hạn.
 * Không tự động đặt lệnh — user phải confirm.
 */
class LCNI_DnseOrderService {

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
    // PLACE ORDER
    // =========================================================================

    /**
     * Đặt lệnh mua/bán.
     * Gọi sau khi user đã confirm trên TradeConfirmModal.
     *
     * @param int    $user_id
     * @param array  $order_params  {
     *   account_no, symbol, side (buy|sell), order_type (LO|MP|ATO|ATC),
     *   price, quantity, loan_package_id (0 = cash), account_type (spot|margin)
     * }
     * @return array|WP_Error  ['order_id' => string, 'message' => string]
     */
    public function place_order( int $user_id, array $order_params ) {
        // Validate trading token
        if ( ! $this->repo->is_trading_token_valid( $user_id ) ) {
            return new WP_Error( 'dnse_trading_expired',
                'Trading token đã hết hạn. Vui lòng xác thực OTP lại.'
            );
        }

        $creds         = $this->repo->get_credentials( $user_id );
        $jwt           = $creds['jwt_token']     ?? '';
        $trading_token = $creds['trading_token'] ?? '';

        if ( $jwt === '' || $trading_token === '' ) {
            return new WP_Error( 'dnse_not_connected', 'Chưa kết nối DNSE.' );
        }

        // Sanitize params
        $account_no      = sanitize_text_field( $order_params['account_no'] ?? '' );
        $symbol          = strtoupper( sanitize_text_field( $order_params['symbol'] ?? '' ) );
        $side            = strtolower( $order_params['side'] ?? 'buy' );
        $order_type      = strtoupper( $order_params['order_type'] ?? 'LO' );
        $price           = (float) ( $order_params['price'] ?? 0 );
        $quantity        = (int) ( $order_params['quantity'] ?? 0 );
        $loan_package_id = (int) ( $order_params['loan_package_id'] ?? 0 );
        $account_type    = $order_params['account_type'] ?? 'spot';

        // Validate
        if ( $account_no === '' || $symbol === '' ) {
            return new WP_Error( 'dnse_order_invalid', 'Thiếu thông tin tài khoản hoặc mã CK.' );
        }
        if ( ! in_array( $side, [ 'buy', 'sell' ], true ) ) {
            return new WP_Error( 'dnse_order_invalid', 'Side phải là buy hoặc sell.' );
        }
        if ( ! in_array( $order_type, [ 'LO', 'MP', 'ATO', 'ATC' ], true ) ) {
            return new WP_Error( 'dnse_order_invalid', 'Loại lệnh không hợp lệ.' );
        }
        if ( $quantity <= 0 ) {
            return new WP_Error( 'dnse_order_invalid', 'Khối lượng phải lớn hơn 0.' );
        }
        if ( $order_type === 'LO' && $price <= 0 ) {
            return new WP_Error( 'dnse_order_invalid', 'Giá lệnh LO phải lớn hơn 0.' );
        }

        // Admin limit check
        $settings   = get_option( 'lcni_dnse_order_settings', [] );
        $max_qty    = (int) ( $settings['max_quantity_per_order'] ?? 0 );
        $max_val    = (float) ( $settings['max_value_per_order'] ?? 0 );

        if ( $max_qty > 0 && $quantity > $max_qty ) {
            return new WP_Error( 'dnse_order_limit',
                "Vượt giới hạn KL tối đa mỗi lệnh ({$max_qty} cổ phiếu)."
            );
        }
        $order_value = $price * $quantity * 1000;
        if ( $max_val > 0 && $order_value > $max_val ) {
            return new WP_Error( 'dnse_order_limit',
                'Vượt giới hạn giá trị tối đa mỗi lệnh.'
            );
        }

        // Call DNSE API
        $result = $this->api->place_order(
            $jwt, $trading_token,
            $account_no, $symbol, $side, $order_type,
            $price, $quantity, $loan_package_id
        );

        if ( is_wp_error( $result ) ) return $result;

        $order_id = (string) ( $result['orderId'] ?? $result['id'] ?? '' );

        // Lưu vào DB
        $this->repo->upsert_orders( $user_id, $account_no, $account_type, [ $result ] );

        // Log
        error_log( sprintf(
            '[LCNI DNSE] User %d placed order: %s %s %s qty=%d price=%.1f → orderId=%s',
            $user_id, $side, $symbol, $order_type, $quantity, $price, $order_id
        ) );

        return [
            'order_id' => $order_id,
            'message'  => sprintf( 'Đặt lệnh %s %s thành công. Mã lệnh: %s',
                strtoupper( $side ), $symbol, $order_id
            ),
            'raw' => $result,
        ];
    }

    // =========================================================================
    // CANCEL ORDER
    // =========================================================================

    /**
     * Hủy lệnh.
     */
    public function cancel_order( int $user_id, string $dnse_order_id, string $account_no, string $account_type = 'spot' ) {
        if ( ! $this->repo->is_trading_token_valid( $user_id ) ) {
            return new WP_Error( 'dnse_trading_expired', 'Trading token đã hết hạn.' );
        }

        $creds         = $this->repo->get_credentials( $user_id );
        $jwt           = $creds['jwt_token']     ?? '';
        $trading_token = $creds['trading_token'] ?? '';

        $result = $this->api->cancel_order( $jwt, $trading_token, $dnse_order_id, $account_no );
        if ( is_wp_error( $result ) ) return $result;

        return true;
    }

    // =========================================================================
    // SIGNAL → ORDER BRIDGE
    // =========================================================================

    /**
     * Lấy danh sách open signals của user kèm thông tin đề xuất đặt lệnh.
     * Mỗi signal trả về: symbol, side=buy, giá gợi ý, SL gợi ý, rule name.
     */
    public function get_open_signals_for_user( int $user_id ): array {
        global $wpdb;

        $signal_table = $wpdb->prefix . 'lcni_recommend_signal';
        $rule_table   = $wpdb->prefix . 'lcni_recommend_rule';
        $ohlc_table   = $wpdb->prefix . 'lcni_ohlc_latest';

        $prev = $wpdb->suppress_errors( true );

        $rows = $wpdb->get_results(
            "SELECT s.id, s.rule_id, s.symbol, s.timeframe,
                    s.entry_time, s.entry_price, s.initial_sl,
                    s.current_price, s.r_multiple, s.position_state,
                    s.status, s.holding_days,
                    r.name AS rule_name, r.initial_sl_pct, r.risk_reward,
                    COALESCE(o.close_price, s.current_price) AS latest_price
             FROM {$signal_table} s
             LEFT JOIN {$rule_table} r ON r.id = s.rule_id
             LEFT JOIN {$ohlc_table} o ON o.symbol = s.symbol
             WHERE s.status = 'open'
             ORDER BY s.entry_time DESC
             LIMIT 100",
            ARRAY_A
        ) ?: [];

        $wpdb->suppress_errors( $prev );

        // Kiểm tra user đã nắm giữ mã nào (từ DNSE positions cache)
        $positions = $this->repo->get_positions( $user_id );
        $held = [];
        foreach ( $positions as $pos ) {
            $held[ $pos['symbol'] ] = (float) $pos['quantity'];
        }

        return array_map( function ( $row ) use ( $held ) {
            $entry_price  = (float) $row['entry_price'];
            $latest_price = (float) ( $row['latest_price'] ?: $row['current_price'] );
            $sl           = (float) $row['initial_sl'];
            $pnl_pct      = $entry_price > 0
                ? ( $latest_price - $entry_price ) / $entry_price * 100 : 0;

            return [
                'signal_id'     => (int) $row['id'],
                'rule_id'       => (int) $row['rule_id'],
                'rule_name'     => $row['rule_name'] ?? '—',
                'symbol'        => $row['symbol'],
                'timeframe'     => $row['timeframe'],
                'entry_time'    => (int) $row['entry_time'],
                'entry_price'   => $entry_price,
                'suggested_price' => $latest_price,  // giá gợi ý = giá hiện tại
                'initial_sl'    => $sl,
                'sl_pct'        => (float) $row['initial_sl_pct'],
                'risk_reward'   => (float) $row['risk_reward'],
                'r_multiple'    => (float) $row['r_multiple'],
                'position_state'=> $row['position_state'],
                'holding_days'  => (int) $row['holding_days'],
                'pnl_pct'       => round( $pnl_pct, 2 ),
                'already_held'  => isset( $held[ $row['symbol'] ] ),
                'held_qty'      => $held[ $row['symbol'] ] ?? 0,
            ];
        }, $rows );
    }

    // =========================================================================
    // LOAN PACKAGES (cho margin)
    // =========================================================================

    /**
     * Lấy danh sách gói vay cho tiểu khoản.
     */
    public function get_loan_packages( int $user_id, string $account_no, string $type = 'spot' ) {
        $creds = $this->repo->get_credentials( $user_id );
        $jwt   = $creds['jwt_token'] ?? '';
        if ( $jwt === '' ) {
            return new WP_Error( 'dnse_not_connected', 'Chưa kết nối DNSE.' );
        }
        return $this->api->get_loan_packages( $jwt, $account_no, $type );
    }
}
