<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UserRuleEngine
 *
 * Nhận hooks từ SignalRepository và DailyCronService
 * để mirror signals vào UserRule của từng user.
 *
 * Flow:
 *   create_signal  → on_system_signal_created()  → tạo user_signal + sync portfolio + email
 *   update_metrics → on_system_signal_updated()  → cập nhật user_signal
 *   close_signal   → on_system_signal_closed()   → đóng user_signal + sync portfolio + email
 */
class UserRuleEngine {

    private UserRuleRepository $repo;
    private ?LCNI_DnseTradingApiClient $dnse;

    public function __construct( UserRuleRepository $repo, $dnse_client = null ) {
        $this->repo = $repo;
        $this->dnse = $dnse_client;
    }

    public function register_hooks(): void {
        add_action( 'lcni_signal_created', [ $this, 'on_system_signal_created' ], 10, 5 );
        add_action( 'lcni_signal_updated', [ $this, 'on_system_signal_updated' ], 10, 5 );
        add_action( 'lcni_signal_closed',  [ $this, 'on_system_signal_closed'  ], 10, 6 );
        // Cron: kiểm tra và đặt lệnh bán pending khi CK đã về T+2
        add_action( 'lcni_recommend_daily_cron', [ $this, 'process_pending_sell_orders' ] );
    }

    // =========================================================================
    // HOOK: Signal mới được tạo
    // =========================================================================

    public function on_system_signal_created(
        int    $system_signal_id,
        int    $rule_id,
        string $symbol,
        float  $entry_price,
        int    $entry_time
    ): void {
        $active_user_rules = $this->repo->get_all_active_user_rules();

        foreach ( $active_user_rules as $ur ) {
            if ( (int) $ur['rule_id'] !== $rule_id ) continue;

            $ur_id   = (int) $ur['id'];
            $user_id = (int) $ur['user_id'];

            // 1. Chỉ nhận signal từ start_date trở đi
            $start_ts = strtotime( (string) $ur['start_date'] );
            if ( $entry_time < $start_ts ) continue;

            // 2. Chưa mirror signal này chưa
            if ( $this->repo->signal_already_mirrored( $ur_id, $system_signal_id ) ) continue;

            // 3. Kiểm tra max_symbols
            $open_count = $this->repo->count_open_positions( $ur_id );
            if ( $open_count >= (int) $ur['max_symbols'] ) {
                UserRuleNotifier::send( 'ur_max_symbols', $user_id, [
                    'rule_name'   => $ur['rule_name'] ?? '',
                    'symbol'      => $symbol,
                    'max_symbols' => (int) $ur['max_symbols'],
                ] );
                continue;
            }

            // 4. Tính position size
            $position = $this->calc_position( $ur, $entry_price );
            if ( $position['shares'] <= 0 ) continue;

            // 5. Tính initial_sl từ rule
            $initial_sl = $entry_price * ( 1 - (float)$ur['initial_sl_pct'] / 100 );

            // 6. Tạo user_signal
            $us_id = $this->repo->create_user_signal( [
                'user_rule_id'      => $ur_id,
                'system_signal_id'  => $system_signal_id,
                'symbol'            => $symbol,
                'entry_price'       => $entry_price,
                'entry_time'        => $entry_time,
                'initial_sl'        => $initial_sl,
                'shares'            => $position['shares'],
                'allocated_capital' => $position['allocated'],
            ] );

            if ( $us_id <= 0 ) continue;

            $is_paper   = (bool) (int) $ur['is_paper'];
            $trade_date = $entry_time > 0 ? wp_date( 'Y-m-d', $entry_time, wp_timezone() ) : current_time( 'Y-m-d' );

            // 7a. Sync vào Portfolio ẢO (is_paper = 1)
            if ( $is_paper ) {
                $this->sync_to_paper_portfolio( $ur, $symbol, $entry_price, $position['shares'], $trade_date, 'buy', $us_id );
            }

            // 7b. Auto-order DNSE nếu tài khoản THẬT
            if ( ! $is_paper && (int)$ur['auto_order'] && $ur['account_id'] && $this->dnse ) {
                $this->place_dnse_order( $ur, $symbol, $entry_price, $position['shares'], $us_id );
            }

            // 7c. Fire inbox event cho Auto Rule (cả paper lẫn thật)
            if ( (int)$ur['auto_order'] ) {
                do_action( 'lcni_auto_rule_triggered', $user_id, $symbol, [
                    'rule_name' => $ur['rule_name'] ?? '',
                    'is_paper'  => $is_paper,
                    'price'     => $entry_price,
                    'shares'    => $position['shares'] ?? 0,
                ] );
            }

            // 8. Email thông báo
            UserRuleNotifier::send( 'ur_signal_opened', $user_id, [
                'rule_name'        => $ur['rule_name'] ?? '',
                'symbol'           => $symbol,
                'entry_price'      => UserRuleNotifier::fmt_price( $entry_price ),
                'initial_sl'       => UserRuleNotifier::fmt_price( $initial_sl ),
                'shares'           => number_format( $position['shares'] ),
                'allocated_capital'=> UserRuleNotifier::fmt_vnd( $position['allocated'] ),
                'trade_type'       => $is_paper ? '📄 Paper Trade (mô phỏng)' : '💰 Giao dịch thật',
            ] );
        }
    }

    // =========================================================================
    // HOOK: Metrics cập nhật hàng ngày
    // =========================================================================

    public function on_system_signal_updated(
        int    $system_signal_id,
        float  $current_price,
        float  $r_multiple,
        string $position_state,
        int    $holding_days
    ): void {
        global $wpdb;
        $t = $wpdb->prefix . 'lcni_user_signals';

        $user_signals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_rule_id, initial_sl, entry_price, shares, allocated_capital
             FROM {$t} WHERE system_signal_id=%d AND status='open'",
            $system_signal_id
        ), ARRAY_A ) ?: [];

        foreach ( $user_signals as $us ) {
            $ep    = (float) $us['entry_price'];
            $sl    = (float) $us['initial_sl'];
            $risk  = max( 0.0001, $ep - $sl );
            $r_mul = $risk > 0 ? ( $current_price - $ep ) / $risk : 0;

            $user_state = $position_state; // fallback
            $ur_meta    = $wpdb->get_row( $wpdb->prepare(
                "SELECT r.add_at_r, r.exit_at_r
                 FROM {$wpdb->prefix}lcni_user_rules ur
                 JOIN {$wpdb->prefix}lcni_recommend_rule r ON r.id = ur.rule_id
                 WHERE ur.id = %d LIMIT 1",
                (int) $us['user_rule_id']
            ), ARRAY_A );
            if ( $ur_meta ) {
                $user_state = ( new PositionEngine() )->resolve_state(
                    $r_mul,
                    (float) $ur_meta['add_at_r'],
                    (float) $ur_meta['exit_at_r']
                );
            }

            $this->repo->update_user_signal_metrics(
                (int) $us['id'], $current_price, $r_mul, $user_state, $holding_days
            );
        }
    }

    // =========================================================================
    // HOOK: Signal đóng
    // =========================================================================

    public function on_system_signal_closed(
        int    $system_signal_id,
        float  $exit_price,
        int    $exit_time,
        float  $final_r,
        int    $holding_days,
        string $exit_reason
    ): void {
        global $wpdb;
        $t = $wpdb->prefix . 'lcni_user_signals';

        $user_signals = $wpdb->get_results( $wpdb->prepare(
            "SELECT us.*, ur.is_paper, ur.account_id, ur.auto_order, ur.user_id,
                    r.name AS rule_name
             FROM {$t} us
             JOIN {$wpdb->prefix}lcni_user_rules ur ON ur.id = us.user_rule_id
             JOIN {$wpdb->prefix}lcni_recommend_rule r ON r.id = ur.rule_id
             WHERE us.system_signal_id=%d AND us.status='open'",
            $system_signal_id
        ), ARRAY_A ) ?: [];

        foreach ( $user_signals as $us ) {
            $ep      = (float) $us['entry_price'];
            $pnl_vnd = ( $exit_price - $ep ) * 1000 * (int) $us['shares'];

            $this->repo->close_user_signal(
                (int) $us['id'], $exit_price, $exit_time,
                round( $final_r, 6 ), $holding_days, $exit_reason, round( $pnl_vnd, 4 )
            );

            $this->repo->recalculate_performance( (int) $us['user_rule_id'] );

            $user_id    = (int) $us['user_id'];
            $is_paper   = (bool) (int) $us['is_paper'];
            $trade_date = $exit_time > 0 ? wp_date( 'Y-m-d', $exit_time, wp_timezone() ) : current_time( 'Y-m-d' );

            // Sync đóng vào Portfolio ẢO
            if ( $is_paper ) {
                $ur = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}lcni_user_rules WHERE id=%d", (int)$us['user_rule_id']
                ), ARRAY_A );
                if ( $ur ) {
                    $this->sync_to_paper_portfolio( $ur, $us['symbol'], $exit_price, (int)$us['shares'], $trade_date, 'sell', (int)$us['id'] );
                }
            }

            // Auto-sell DNSE: queue lệnh bán, cron sẽ đặt khi CK đã về (T+2)
            if ( ! $is_paper && (int) $us['auto_order'] && ! empty( $us['account_id'] ) && ! empty( $us['dnse_order_id'] ) ) {
                $wpdb->update(
                    $wpdb->prefix . 'lcni_user_signals',
                    [
                        'sell_status'    => 'pending',
                        'sell_queued_at' => $exit_time ?: time(),
                    ],
                    [ 'id' => (int) $us['id'] ],
                    [ '%s', '%d' ],
                    [ '%d' ]
                );
                // Thông báo user: lệnh bán đang chờ T+2
                $t2_date = $this->calc_t2_date( $exit_time ?: time() );
                UserRuleNotifier::send( 'ur_sell_queued', $user_id, [
                    'rule_name'  => $us['rule_name'] ?? '',
                    'symbol'     => $us['symbol'],
                    'exit_price' => UserRuleNotifier::fmt_price( $exit_price ),
                    'shares'     => number_format( (int) $us['shares'] ),
                    'account_no' => $us['account_id'],
                    't2_date'    => $t2_date,
                ] );
            }

            // Email thông báo đóng signal
            $pnl_color  = $final_r >= 0 ? 'style="color:#16a34a"' : 'style="color:#dc2626"';
            UserRuleNotifier::send( 'ur_signal_closed', $user_id, [
                'rule_name'         => $us['rule_name'] ?? '',
                'symbol'            => $us['symbol'],
                'entry_price'       => UserRuleNotifier::fmt_price( $ep ),
                'exit_price'        => UserRuleNotifier::fmt_price( $exit_price ),
                'final_r'           => ( $final_r >= 0 ? '+' : '' ) . number_format( $final_r, 2 ),
                'pnl_vnd'           => UserRuleNotifier::fmt_vnd( $pnl_vnd ),
                'pnl_color'         => $pnl_color,
                'exit_reason'       => $exit_reason,
                'exit_reason_label' => UserRuleNotifier::exit_reason_label( $exit_reason ),
                'holding_days'      => $holding_days,
            ] );
        }
    }

    // =========================================================================
    // PORTFOLIO SYNC
    // =========================================================================

    /**
     * Sync giao dịch vào Portfolio ẢO (paper trade) của user.
     * Tự động tìm hoặc tạo portfolio có tên = tên rule.
     */
    private function sync_to_paper_portfolio( array $ur, string $symbol, float $price, int $shares, string $trade_date, string $type, int $us_id ): void {
        if ( ! class_exists( 'LCNI_Portfolio_Service' ) || ! class_exists( 'LCNI_Portfolio_Repository' ) ) return;

        try {
            global $wpdb;
            $user_id   = (int) $ur['user_id'];
            $rule_name = $ur['rule_name'] ?? ( 'UserRule #' . $ur['rule_id'] );

            $port_repo  = new LCNI_Portfolio_Repository();
            $port_svc   = new LCNI_Portfolio_Service( $port_repo );

            // Tìm portfolio paper-trade cho rule này
            $portfolio_id = $port_repo->get_or_create_user_rule_portfolio( $user_id, (int)$ur['rule_id'], $rule_name );
            if ( ! $portfolio_id ) return;

            // Price DB format: nghìn đồng (21.5 = 21,500đ)
            $port_svc->add_transaction( $portfolio_id, $user_id, [
                'symbol'     => strtoupper( $symbol ),
                'type'       => $type,
                'trade_date' => $trade_date,
                'quantity'   => $shares,
                'price'      => $price,   // already in thousands format
                'fee'        => 0,
                'tax'        => 0,
                'note'       => 'Auto từ UserRule #' . $ur['id'] . ' — signal #' . $us_id,
                'source'     => 'user_rule',
            ] );
        } catch ( \Throwable $e ) {
            error_log( '[UserRuleEngine] sync_to_paper_portfolio failed: ' . $e->getMessage() );
        }
    }

    // =========================================================================
    // DNSE ORDER
    // =========================================================================

    private function place_dnse_order( array $ur, string $symbol, float $entry_price, int $shares, int $us_id ): void {
        if ( ! $this->dnse ) return;
        try {
            global $wpdb;
            $user_id    = (int) $ur['user_id'];
            $account_no = (string) $ur['account_id'];

            // BUG FIX: lấy trading_token từ DnseTradingRepository (encrypted DB)
            if ( ! class_exists( 'LCNI_DnseTradingRepository' ) ) {
                error_log( '[UserRuleEngine] DnseTradingRepository not loaded — cannot place DNSE order.' );
                return;
            }
            $dnse_repo = new LCNI_DnseTradingRepository( $wpdb );

            // Chỉ đặt lệnh nếu user đã bật perm_trade
            if ( ! $dnse_repo->has_permission( $user_id, 'perm_trade' ) ) {
                error_log( "[UserRuleEngine] User {$user_id} chưa bật perm_trade — auto order skipped." );
                return;
            }

            $creds = $dnse_repo->get_credentials( $user_id );

            if ( ! $creds || empty( $creds['trading_token'] ) ) {
                error_log( "[UserRuleEngine] No trading_token for user {$user_id} — order skipped." );
                UserRuleNotifier::send( 'ur_order_failed', $user_id, [
                    'rule_name'     => $ur['rule_name'] ?? '',
                    'symbol'        => $symbol,
                    'error_message' => 'Chưa xác thực DNSE hoặc chưa có trading token.',
                ] );
                return;
            }

            // Kiểm tra token còn hạn
            if ( (int) ( $creds['trading_expires_at'] ?? 0 ) < time() ) {
                error_log( "[UserRuleEngine] trading_token expired for user {$user_id} — order skipped." );
                UserRuleNotifier::send( 'ur_dnse_token_expired', $user_id, [
                    'rule_name' => $ur['rule_name'] ?? '',
                    'symbol'    => $symbol,
                ] );
                return;
            }

            $trading_token = $creds['trading_token'];
            $jwt           = $creds['jwt'] ?? '';   // jwt dùng cho Authorization header

            // Vol phải là bội số 100 (lô chẵn sàn HOSE/HNX)
            // calc_position trả về shares đơn vị cổ → round xuống 100
            $shares_lot = (int) ( floor( $shares / 100 ) * 100 );
            if ( $shares_lot <= 0 ) {
                error_log( "[UserRuleEngine] Shares after lot-rounding = 0 for {$symbol} (raw: {$shares}) — order skipped." );
                return;
            }

            $result = $this->dnse->place_order(
                $jwt,
                $trading_token,
                $account_no,
                $symbol,
                'buy',          // DnseTradingApiClient tự convert → 'NB'
                'LO',
                $entry_price,   // DB format (nghìn VNĐ) — ApiClient tự × 1000
                $shares_lot,    // lô chẵn 100
                0               // loanPackageId = 0 → tiền mặt T+2 (không phải T+2.5 margin)
            );

            // Response field là 'id' theo DNSE API v2 docs
            $order_id_raw = $result['id'] ?? $result['orderId'] ?? null;

            if ( ! empty( $order_id_raw ) ) {
                $order_id = (string) $order_id_raw;

                // Lưu order_id vào user_signal
                $wpdb->update(
                    $wpdb->prefix . 'lcni_user_signals',
                    [ 'dnse_order_id' => $order_id ],
                    [ 'id' => $us_id ]
                );

                // Sync vào Portfolio DNSE (source='dnse')
                $this->sync_to_dnse_portfolio( $ur, $symbol, $entry_price, $shares, $order_id );

                // Email thành công
                UserRuleNotifier::send( 'ur_order_placed', $user_id, [
                    'rule_name'    => $ur['rule_name'] ?? '',
                    'symbol'       => $symbol,
                    'entry_price'  => UserRuleNotifier::fmt_price( $entry_price ),
                    'shares'       => number_format( $shares_lot ),
                    'dnse_order_id'=> $order_id,
                    'account_no'   => $account_no,
                ] );
            } else {
                $err_msg = $result['message'] ?? $result['error'] ?? 'Phản hồi không hợp lệ từ DNSE.';
                error_log( "[UserRuleEngine] DNSE order failed for {$symbol}: {$err_msg}" );
                UserRuleNotifier::send( 'ur_order_failed', $user_id, [
                    'rule_name'     => $ur['rule_name'] ?? '',
                    'symbol'        => $symbol,
                    'error_message' => $err_msg,
                ] );
            }
        } catch ( \Throwable $e ) {
            error_log( '[UserRuleEngine] DNSE order exception: ' . $e->getMessage() );
            UserRuleNotifier::send( 'ur_order_failed', (int)$ur['user_id'], [
                'rule_name'     => $ur['rule_name'] ?? '',
                'symbol'        => $symbol,
                'error_message' => $e->getMessage(),
            ] );
        }
    }

    /**
     * Sync lệnh DNSE đã đặt vào Portfolio DNSE của user.
     * Portfolio DNSE được tạo tự động theo account_no.
     */
    private function sync_to_dnse_portfolio( array $ur, string $symbol, float $price, int $shares, string $order_id ): void {
        if ( ! class_exists( 'LCNI_Portfolio_Service' ) || ! class_exists( 'LCNI_Portfolio_Repository' ) ) return;
        try {
            global $wpdb;
            $user_id    = (int) $ur['user_id'];
            $account_no = (string) $ur['account_id'];

            $port_repo = new LCNI_Portfolio_Repository();

            // Tìm hoặc tạo portfolio DNSE theo account_no
            $portfolio_id = $port_repo->get_or_create_dnse_portfolio( $user_id, $account_no, 'DNSE ' . $account_no );
            if ( ! $portfolio_id ) return;

            $port_svc = new LCNI_Portfolio_Service( $port_repo );
            $port_svc->add_transaction( $portfolio_id, $user_id, [
                'symbol'       => strtoupper( $symbol ),
                'type'         => 'buy',
                'trade_date'   => current_time( 'Y-m-d' ),
                'quantity'     => $shares,
                'price'        => $price,
                'fee'          => 0,
                'tax'          => 0,
                'note'         => 'DNSE #' . $order_id . ' — UserRule #' . $ur['id'],
                'dnse_order_id'=> $order_id,
                'source'       => 'dnse',
            ] );
        } catch ( \Throwable $e ) {
            error_log( '[UserRuleEngine] sync_to_dnse_portfolio failed: ' . $e->getMessage() );
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function calc_position( array $ur, float $entry_price ): array {
        if ( $entry_price <= 0.5 || $entry_price > 200.0 ) {
            error_log( sprintf( '[UserRuleEngine] calc_position: entry_price=%.4f ngoài range hợp lệ (0.5-200 nghìn đồng)', $entry_price ) );
            return [ 'shares' => 0, 'allocated' => 0.0 ];
        }

        $capital      = (float) $ur['capital'];
        $risk_pct     = (float) $ur['risk_per_trade'];
        $allocated    = $capital * $risk_pct / 100;
        $entry_vnd    = $entry_price * 1000;

        // Tính số cổ thô → round xuống bội số 100 (lô chẵn sàn HOSE/HNX/UPCoM)
        $shares_raw   = $entry_vnd > 0 ? (int) floor( $allocated / $entry_vnd ) : 0;
        $shares       = (int) ( floor( $shares_raw / 100 ) * 100 );

        $actual_alloc = $shares * $entry_vnd;
        return [ 'shares' => $shares, 'allocated' => $actual_alloc ];
    }

    // =========================================================================
    // PENDING SELL — chờ T+2 về mới đặt lệnh bán
    // =========================================================================

    /**
     * Chạy mỗi cron tick (lcni_recommend_daily_cron).
     * Query tất cả user_signals có sell_status='pending',
     * tính T+2 từ sell_queued_at, nếu đã qua → đặt lệnh bán DNSE.
     */
    public function process_pending_sell_orders(): void {
        // Chỉ chạy trong giờ giao dịch: 9:00–14:45 các ngày T2-T6
        $tz  = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );
        $dow = (int) $now->format( 'N' );  // 1=T2..5=T6
        if ( $dow > 5 ) return;            // cuối tuần
        $hm = (int) $now->format( 'Hi' );  // ví dụ 1435 = 14:35
        if ( $hm < 900 || $hm > 1445 ) return; // ngoài giờ GD

        global $wpdb;
        $t = $wpdb->prefix . 'lcni_user_signals';

        $rows = $wpdb->get_results(
            "SELECT us.*,
                    ur.user_id, ur.account_id, ur.rule_id,
                    r.name AS rule_name
             FROM {$t} us
             JOIN {$wpdb->prefix}lcni_user_rules ur ON ur.id = us.user_rule_id
             JOIN {$wpdb->prefix}lcni_recommend_rule r ON r.id = ur.rule_id
             WHERE us.sell_status = 'pending'
               AND us.sell_queued_at IS NOT NULL",
            ARRAY_A
        ) ?: [];

        foreach ( $rows as $us ) {
            $queued_at  = (int) $us['sell_queued_at'];
            $t2_ts      = $this->calc_t2_timestamp( $queued_at );

            // Chưa đến T+2 → bỏ qua, cron lần sau
            if ( time() < $t2_ts ) continue;

            // Quá 10 ngày làm việc mà chưa bán được → đánh dấu failed, thông báo user
            $max_ts = $this->calc_t2_timestamp( $queued_at, 10 );
            if ( time() > $max_ts ) {
                $wpdb->update( $t, [ 'sell_status' => 'failed' ], [ 'id' => (int) $us['id'] ], [ '%s' ], [ '%d' ] );
                UserRuleNotifier::send( 'ur_sell_failed', (int) $us['user_id'], [
                    'rule_name'  => $us['rule_name'] ?? '',
                    'symbol'     => $us['symbol'],
                    'shares'     => number_format( (int) $us['shares'] ),
                    'account_no' => $us['account_id'],
                    'reason'     => 'Quá thời hạn 10 ngày, vui lòng kiểm tra và bán thủ công trên DNSE.',
                ] );
                continue;
            }

            $this->execute_pending_sell( $us );
        }
    }

    /**
     * Đặt lệnh bán cho 1 pending sell record.
     */
    private function execute_pending_sell( array $us ): void {
        global $wpdb;
        $t          = $wpdb->prefix . 'lcni_user_signals';
        $user_id    = (int) $us['user_id'];
        $account_no = (string) $us['account_id'];
        $symbol     = (string) $us['symbol'];
        $shares     = (int) $us['shares'];
        $exit_price = (float) $us['exit_price'];

        // Vol phải là bội số 100
        $shares_lot = (int) ( floor( $shares / 100 ) * 100 );
        if ( $shares_lot <= 0 ) {
            $wpdb->update( $t, [ 'sell_status' => 'failed' ], [ 'id' => (int) $us['id'] ], [ '%s' ], [ '%d' ] );
            return;
        }

        if ( ! class_exists( 'LCNI_DnseTradingRepository' ) ) return;

        $dnse_repo = new LCNI_DnseTradingRepository( $wpdb );

        // Chỉ thực thi nếu user vẫn còn perm_trade
        if ( ! $dnse_repo->has_permission( $user_id, 'perm_trade' ) ) {
            error_log( "[UserRuleEngine] Pending sell: user {$user_id} chưa bật perm_trade — skipped." );
            return;
        }

        $creds = $dnse_repo->get_credentials( $user_id );

        if ( ! $creds || empty( $creds['trading_token'] ) ) {
            // Token chưa có — thử lại lần sau (giữ pending)
            error_log( "[UserRuleEngine] Pending sell: no trading_token for user {$user_id}, will retry." );
            return;
        }

        if ( (int) ( $creds['trading_expires_at'] ?? 0 ) < time() ) {
            // Token hết hạn — thông báo user tự gia hạn
            UserRuleNotifier::send( 'ur_dnse_token_expired', $user_id, [
                'rule_name' => $us['rule_name'] ?? '',
                'symbol'    => $symbol,
            ] );
            return;
        }

        if ( ! $this->dnse ) return;

        try {
            $result = $this->dnse->place_order(
                $creds['jwt'] ?? '',
                $creds['trading_token'],
                $account_no,
                $symbol,
                'sell',   // DnseTradingApiClient convert → 'NS'
                'LO',
                $exit_price,   // DB format (nghìn VNĐ)
                $shares_lot,
                0              // loanPackageId=0 → bán CK đã về, không dùng ứng
            );

            $order_id_raw = $result['id'] ?? $result['orderId'] ?? null;

            if ( ! empty( $order_id_raw ) ) {
                $wpdb->update( $t, [
                    'sell_status'  => 'placed',
                    'sell_order_id'=> (string) $order_id_raw,
                ], [ 'id' => (int) $us['id'] ], [ '%s', '%s' ], [ '%d' ] );

                UserRuleNotifier::send( 'ur_sell_placed', $user_id, [
                    'rule_name'     => $us['rule_name'] ?? '',
                    'symbol'        => $symbol,
                    'exit_price'    => UserRuleNotifier::fmt_price( $exit_price ),
                    'shares'        => number_format( $shares_lot ),
                    'sell_order_id' => (string) $order_id_raw,
                    'account_no'    => $account_no,
                ] );

                error_log( "[UserRuleEngine] Pending sell placed: {$symbol} × {$shares_lot} order #{$order_id_raw}" );

            } else {
                $err = is_wp_error( $result )
                    ? $result->get_error_message()
                    : ( $result['message'] ?? $result['error'] ?? 'Phản hồi không hợp lệ.' );

                error_log( "[UserRuleEngine] Pending sell failed for {$symbol}: {$err}" );

                // Giữ pending để cron retry lần sau (trừ khi đã quá hạn)
                UserRuleNotifier::send( 'ur_sell_failed', $user_id, [
                    'rule_name'  => $us['rule_name'] ?? '',
                    'symbol'     => $symbol,
                    'shares'     => number_format( $shares_lot ),
                    'account_no' => $account_no,
                    'reason'     => $err,
                ] );
            }
        } catch ( \Throwable $e ) {
            error_log( '[UserRuleEngine] execute_pending_sell exception: ' . $e->getMessage() );
        }
    }

    /**
     * Tính timestamp T+N ngày làm việc từ $from_ts.
     * Ngày làm việc = T2-T6, bỏ qua T7-CN.
     *
     * T+2.5 theo DNSE: khớp T+0 → CK về sáng T+2 →
     * bán được từ phiên chiều T+2 (sau 14:30 ATC).
     * → Set giờ 14:35 để cron đặt lệnh ngay đầu phiên chiều.
     */
    private function calc_t2_timestamp( int $from_ts, int $business_days = 2 ): int {
        $tz  = wp_timezone();
        $dt  = ( new \DateTimeImmutable( '@' . $from_ts ) )->setTimezone( $tz );
        $added = 0;
        while ( $added < $business_days ) {
            $dt  = $dt->modify( '+1 day' );
            $dow = (int) $dt->format( 'N' ); // 1=T2 ... 7=CN
            if ( $dow <= 5 ) $added++;
        }
        // 14:35 — sau ATC phiên chiều mở (14:30), CK đã có thể bán T+2.5
        return (int) $dt->setTime( 14, 35, 0 )->getTimestamp();
    }

    /**
     * Trả về chuỗi ngày T+2 dạng dd/mm/yyyy để hiển thị cho user.
     */
    private function calc_t2_date( int $from_ts ): string {
        $ts = $this->calc_t2_timestamp( $from_ts );
        return wp_date( 'd/m/Y', $ts, wp_timezone() );
    }
}
