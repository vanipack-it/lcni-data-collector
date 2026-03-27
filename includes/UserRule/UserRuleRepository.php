<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class UserRuleRepository {

    private wpdb $db;
    private string $rules_t;
    private string $signals_t;
    private string $perf_t;
    private string $sys_signals_t;
    private string $sys_rules_t;

    public function __construct( wpdb $db ) {
        $this->db            = $db;
        $this->rules_t       = $db->prefix . 'lcni_user_rules';
        $this->signals_t     = $db->prefix . 'lcni_user_signals';
        $this->perf_t        = $db->prefix . 'lcni_user_performance';
        $this->sys_signals_t = $db->prefix . 'lcni_recommend_signal';
        $this->sys_rules_t   = $db->prefix . 'lcni_recommend_rule';
    }

    // =========================================================================
    // USER RULES
    // =========================================================================

    public function create_user_rule( array $data ): int {
        $row = [
            'user_id'        => (int)   $data['user_id'],
            'rule_id'        => (int)   $data['rule_id'],
            'is_paper'       => (int)   ( $data['is_paper'] ?? 1 ),
            'capital'        => (float) $data['capital'],
            'risk_per_trade' => (float) ( $data['risk_per_trade'] ?? 2 ),
            'max_symbols'    => (int)   ( $data['max_symbols'] ?? 5 ),
            'start_date'     => sanitize_text_field( (string) $data['start_date'] ),
            'account_id'     => sanitize_text_field( (string) ( $data['account_id'] ?? '' ) ) ?: null,
            'auto_order'     => (int)   ( $data['auto_order'] ?? 0 ),
            'symbol_scope'   => in_array( $data['symbol_scope'] ?? 'all', ['all','watchlist','custom'], true ) ? $data['symbol_scope'] : 'all',
            'watchlist_id'   => ! empty( $data['watchlist_id'] ) ? (int) $data['watchlist_id'] : null,
            'custom_symbols' => ! empty( $data['custom_symbols'] ) ? sanitize_text_field( (string) $data['custom_symbols'] ) : null,
            'status'         => 'active',
        ];
        $this->db->insert( $this->rules_t, $row );
        $id = (int) $this->db->insert_id;
        if ( $id > 0 ) {
            $this->db->insert( $this->perf_t, [
                'user_rule_id'   => $id,
                'current_capital' => $row['capital'],
            ] );
        }
        return $id;
    }

    public function update_user_rule( int $id, int $user_id, array $data ): bool {
        $allowed = [ 'capital','risk_per_trade','max_symbols','account_id','auto_order','status','symbol_scope','watchlist_id','custom_symbols' ];
        $set = [];
        foreach ( $allowed as $k ) {
            if ( array_key_exists( $k, $data ) ) $set[$k] = $data[$k];
        }
        if ( empty($set) ) return false;
        return (bool) $this->db->update( $this->rules_t, $set, [ 'id' => $id, 'user_id' => $user_id ] );
    }

    public function get_user_rule( int $id, int $user_id ): ?array {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT ur.*, r.name AS rule_name, r.timeframe, r.initial_sl_pct, r.risk_reward,
                        r.add_at_r, r.exit_at_r, r.max_hold_days, r.max_loss_pct
                 FROM {$this->rules_t} ur
                 JOIN {$this->sys_rules_t} r ON r.id = ur.rule_id
                 WHERE ur.id = %d AND ur.user_id = %d",
                $id, $user_id
            ), ARRAY_A
        );
    }

    public function get_all_active_user_rules(): array {
        return $this->db->get_results(
            "SELECT ur.*, r.name AS rule_name, r.timeframe, r.initial_sl_pct,
                    r.add_at_r, r.exit_at_r, r.max_hold_days, r.max_loss_pct, r.risk_reward
             FROM {$this->rules_t} ur
             JOIN {$this->sys_rules_t} r ON r.id = ur.rule_id
             WHERE ur.status = 'active'",
            ARRAY_A
        ) ?: [];
    }

    public function list_user_rules( int $user_id ): array {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT ur.*, r.name AS rule_name, r.timeframe, r.risk_reward,
                        p.total_trades, p.win_trades, p.total_r,
                        p.total_pnl_vnd, p.current_capital, p.winrate
                 FROM {$this->rules_t} ur
                 JOIN {$this->sys_rules_t} r ON r.id = ur.rule_id
                 LEFT JOIN {$this->perf_t} p ON p.user_rule_id = ur.id
                 WHERE ur.user_id = %d
                 ORDER BY ur.created_at DESC",
                $user_id
            ), ARRAY_A
        ) ?: [];
    }

    public function delete_user_rule( int $id, int $user_id ): bool {
        // R3 FIX: bọc trong transaction để đảm bảo atomicity
        // Tránh tình trạng signals bị xoá nhưng rule còn tồn tại nếu crash giữa chừng
        $this->db->query( 'START TRANSACTION' );
        try {
            $this->db->delete( $this->signals_t, [ 'user_rule_id' => $id ] );
            $this->db->delete( $this->perf_t,    [ 'user_rule_id' => $id ] );
            $deleted = (bool) $this->db->delete( $this->rules_t, [ 'id' => $id, 'user_id' => $user_id ] );
            if ( $deleted ) {
                $this->db->query( 'COMMIT' );
            } else {
                $this->db->query( 'ROLLBACK' );
            }
            return $deleted;
        } catch ( \Throwable $e ) {
            $this->db->query( 'ROLLBACK' );
            error_log( '[UserRuleRepository] delete_user_rule failed: ' . $e->getMessage() );
            return false;
        }
    }

    // =========================================================================
    // USER SIGNALS
    // =========================================================================

    public function create_user_signal( array $data ): int {
        $this->db->insert( $this->signals_t, [
            'user_rule_id'      => (int)   $data['user_rule_id'],
            'system_signal_id'  => (int)   $data['system_signal_id'],
            'symbol'            => strtoupper( sanitize_text_field( $data['symbol'] ) ),
            'entry_price'       => (float) $data['entry_price'],
            'entry_time'        => (int)   $data['entry_time'],
            'initial_sl'        => (float) ( $data['initial_sl'] ?? 0 ),
            'shares'            => (int)   ( $data['shares'] ?? 0 ),
            'allocated_capital' => (float) ( $data['allocated_capital'] ?? 0 ),
            'current_price'     => (float) $data['entry_price'],
            'r_multiple'        => 0.0,
            'position_state'    => 'EARLY',
            'status'            => 'open',
        ] );
        return (int) $this->db->insert_id;
    }

    public function update_user_signal_metrics( int $id, float $current_price, float $r_multiple, string $state, int $holding_days ): void {
        $this->db->update( $this->signals_t, [
            'current_price'  => $current_price,
            'r_multiple'     => $r_multiple,
            'position_state' => $state,
            'holding_days'   => $holding_days,
        ], [ 'id' => $id ] );
    }

    public function close_user_signal( int $id, float $exit_price, int $exit_time, float $final_r, int $holding_days, string $exit_reason, float $pnl_vnd ): void {
        $this->db->update( $this->signals_t, [
            'status'       => 'closed',
            'exit_price'   => $exit_price,
            'exit_time'    => $exit_time,
            'final_r'      => $final_r,
            'holding_days' => $holding_days,
            'exit_reason'  => $exit_reason,
            'pnl_vnd'      => $pnl_vnd,
        ], [ 'id' => $id ] );
    }

    /** Kiểm tra signal này đã được mirror chưa */
    public function signal_already_mirrored( int $user_rule_id, int $system_signal_id ): bool {
        return (bool) $this->db->get_var( $this->db->prepare(
            "SELECT id FROM {$this->signals_t} WHERE user_rule_id=%d AND system_signal_id=%d LIMIT 1",
            $user_rule_id, $system_signal_id
        ) );
    }

    /** Số open positions hiện tại của user_rule */
    public function count_open_positions( int $user_rule_id ): int {
        return (int) $this->db->get_var( $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->signals_t} WHERE user_rule_id=%d AND status='open'",
            $user_rule_id
        ) );
    }

    public function get_open_user_signals( int $user_rule_id ): array {
        return $this->db->get_results( $this->db->prepare(
            "SELECT us.*, ss.initial_sl AS sys_sl, ss.risk_per_share AS sys_rps
             FROM {$this->signals_t} us
             LEFT JOIN {$this->sys_signals_t} ss ON ss.id = us.system_signal_id
             WHERE us.user_rule_id=%d AND us.status='open'",
            $user_rule_id
        ), ARRAY_A ) ?: [];
    }

    public function list_signals_for_display( int $user_rule_id, string $status = '' ): array {
        // R1 FIX: whitelist status thay vì esc_sql() để đảm bảo an toàn SQL
        $allowed = [ 'open', 'closed', '' ];
        $status  = in_array( $status, $allowed, true ) ? $status : '';
        $where   = $status !== '' ? "AND us.status = '{$status}'" : '';
        return $this->db->get_results( $this->db->prepare(
            "SELECT us.* FROM {$this->signals_t} us
             WHERE us.user_rule_id=%d {$where}
             ORDER BY us.entry_time DESC LIMIT 500",
            $user_rule_id
        ), ARRAY_A ) ?: [];
    }

    /** Lấy closed signals để vẽ equity curve (VNĐ) */
    public function get_equity_curve( int $user_rule_id ): array {
        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT symbol, entry_time, exit_time, final_r, pnl_vnd, holding_days, exit_reason
             FROM {$this->signals_t}
             WHERE user_rule_id=%d AND status='closed' AND final_r IS NOT NULL
             ORDER BY exit_time ASC, entry_time ASC",
            $user_rule_id
        ), ARRAY_A ) ?: [];

        if ( empty($rows) ) return [];
        $cum_r = 0.0; $cum_vnd = 0.0; $result = [];
        foreach ( $rows as $row ) {
            $cum_r   = round( $cum_r   + (float)$row['final_r'],  6 );
            $cum_vnd = round( $cum_vnd + (float)$row['pnl_vnd'],  4 );
            $result[] = [
                'date'         => $row['exit_time'] ? wp_date('Y-m-d', (int)$row['exit_time']) : '',
                'trade_r'      => (float) $row['final_r'],
                'cumulative_r' => $cum_r,
                'pnl_vnd'      => (float) $row['pnl_vnd'],
                'cumulative_vnd' => $cum_vnd,
                'symbol'       => $row['symbol'],
                'exit_reason'  => $row['exit_reason'],
            ];
        }
        return $result;
    }

    // =========================================================================
    // PERFORMANCE
    // =========================================================================

    public function recalculate_performance( int $user_rule_id ): void {
        $ur = $this->db->get_row( $this->db->prepare(
            "SELECT capital FROM {$this->rules_t} WHERE id=%d", $user_rule_id
        ), ARRAY_A );
        if ( ! $ur ) return;

        $initial_capital = (float) $ur['capital'];
        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT final_r, pnl_vnd FROM {$this->signals_t}
             WHERE user_rule_id=%d AND status='closed' AND final_r IS NOT NULL",
            $user_rule_id
        ), ARRAY_A ) ?: [];

        $total = count($rows);
        $wins  = 0; $total_r = 0.0; $total_pnl = 0.0;
        $peak  = $initial_capital; $max_dd_vnd = 0.0; $running = $initial_capital;
        foreach ( $rows as $r ) {
            $fr = (float) $r['final_r']; $pnl = (float) $r['pnl_vnd'];
            if ($fr > 0) $wins++;
            $total_r   += $fr;
            $total_pnl += $pnl;
            $running   += $pnl;
            if ($running > $peak) $peak = $running;
            $dd = $peak - $running;
            if ($dd > $max_dd_vnd) $max_dd_vnd = $dd;
        }
        $winrate = $total > 0 ? round($wins / $total, 4) : 0;
        $current = $initial_capital + $total_pnl;
        // R2 FIX: max_drawdown_pct dùng peak làm mẫu số (đúng định nghĩa drawdown)
        // VD: capital=100tr, peak=150tr, dd_vnd=50tr → pct = 50/150 = 33% (không phải 50/100=50%)
        $max_dd_pct = $peak > 0 ? round( $max_dd_vnd / $peak * 100, 4 ) : 0;

        $this->db->replace( $this->perf_t, [
            'user_rule_id'   => $user_rule_id,
            'total_trades'   => $total,
            'win_trades'     => $wins,
            'lose_trades'    => $total - $wins,
            'total_r'        => round($total_r, 6),
            'total_pnl_vnd'  => round($total_pnl, 4),
            'current_capital'=> round($current, 4),
            'max_drawdown_vnd'=> round($max_dd_vnd, 4),
            'max_drawdown_pct'=> $max_dd_pct,
            'winrate'        => $winrate,
        ] );
    }

    public function get_performance( int $user_rule_id ): ?array {
        return $this->db->get_row( $this->db->prepare(
            "SELECT * FROM {$this->perf_t} WHERE user_rule_id=%d", $user_rule_id
        ), ARRAY_A ) ?: null;
    }
}
