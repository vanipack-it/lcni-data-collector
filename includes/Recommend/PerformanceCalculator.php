<?php

if (!defined('ABSPATH')) {
    exit;
}

class PerformanceCalculator {
    private $wpdb;
    private $signal_table;
    private $performance_table;
    /** @var bool|null  P3 FIX: cache SHOW COLUMNS kết quả thay vì query lại mỗi lần */
    private $has_exit_reason_col = null;

    public function __construct(wpdb $wpdb) {
        $this->wpdb = $wpdb;
        $this->signal_table = $wpdb->prefix . 'lcni_recommend_signal';
        $this->performance_table = $wpdb->prefix . 'lcni_recommend_performance';
    }

    public function get_last_db_error(): string {
        return (string) ( $this->wpdb->last_error ?? '' );
    }

    /**
     * Refresh performance cho các rule.
     * P1 FIX: nhận danh sách rule_ids cụ thể để tránh refresh toàn bộ sau mỗi thao tác nhỏ.
     * Truyền [] (mảng rỗng) để refresh tất cả (dùng cho batch jobs ban đêm).
     *
     * @param int[] $rule_ids  Danh sách rule_id cần refresh. [] = refresh all.
     */
    public function refresh_all( array $rule_ids = [] ): void {
        if ( empty( $rule_ids ) ) {
            // Chỉ gọi khi thực sự cần refresh toàn bộ (ví dụ: admin tool)
            $rule_ids = $this->wpdb->get_col( "SELECT DISTINCT rule_id FROM {$this->signal_table}" );
        }
        foreach ( (array) $rule_ids as $rule_id ) {
            $this->refresh_rule( (int) $rule_id );
        }
    }

    public function refresh_rule($rule_id) {
        $all_signals_total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->signal_table} WHERE rule_id = %d",
                (int) $rule_id
            )
        );

        $closed = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT final_r, holding_days FROM {$this->signal_table} WHERE rule_id = %d AND status = 'closed' AND final_r IS NOT NULL",
                (int) $rule_id
            ),
            ARRAY_A
        );

        $closed_total = count($closed);
        $win = 0;
        $lose = 0;
        $sum_r = 0.0;
        $sum_hold = 0.0;
        $max_r = null;
        $min_r = null;
        $sum_win_r = 0.0;
        $sum_loss_r = 0.0;

        foreach ($closed as $row) {
            $r = (float) $row['final_r'];
            $sum_r += $r;
            $sum_hold += (int) $row['holding_days'];
            $max_r = $max_r === null ? $r : max($max_r, $r);
            $min_r = $min_r === null ? $r : min($min_r, $r);

            // P2 FIX: tách breakeven (r=0) khỏi win để tránh inflate winrate và Kelly%
            if ( $r > 0 ) {
                $win++;
                $sum_win_r += $r;
            } elseif ( $r < 0 ) {
                $lose++;
                $sum_loss_r += abs( $r );
            }
            // r == 0 (breakeven): không đếm vào win hoặc lose
        }

        $winrate    = $closed_total > 0 ? $win / $closed_total : 0;
        $lossrate   = 1 - $winrate;
        $avg_r      = $closed_total > 0 ? $sum_r / $closed_total : 0;
        $avg_hold   = $closed_total > 0 ? $sum_hold / $closed_total : 0;
        $avg_win_r  = $win  > 0 ? $sum_win_r  / $win  : 0;
        $avg_loss_r = $lose > 0 ? $sum_loss_r / $lose : 0;
        $expectancy = ($winrate * $avg_win_r) - ($lossrate * $avg_loss_r);

        // Profit Factor = gross profit / gross loss
        if ($sum_loss_r > 0) {
            $profit_factor = $sum_win_r / $sum_loss_r;
        } elseif ($sum_win_r > 0) {
            $profit_factor = 99.0; // infinite — no losses
        } else {
            $profit_factor = 0.0;
        }

        // Kelly % = W - (L / (avg_win_r / avg_loss_r))
        // Clamped to [0, 1]. Half-Kelly is recommended in practice.
        $kelly_pct = 0.0;
        if ($avg_win_r > 0 && $avg_loss_r > 0 && $winrate > 0) {
            $raw_kelly = $winrate - ($lossrate / ($avg_win_r / $avg_loss_r));
            $kelly_pct = max(0.0, min(1.0, $raw_kelly));
        }

        $this->wpdb->replace($this->performance_table, [
            'rule_id'       => (int) $rule_id,
            'total_trades'  => (int) $all_signals_total,
            'win_trades'    => (int) $win,
            'lose_trades'   => (int) $lose,
            'avg_r'         => (float) $avg_r,
            'winrate'       => (float) $winrate,
            'expectancy'    => (float) $expectancy,
            'max_r'         => $max_r,
            'min_r'         => $min_r,
            'avg_hold_days' => (float) $avg_hold,
            'avg_win_r'     => (float) $avg_win_r,
            'avg_loss_r'    => (float) $avg_loss_r,
            'profit_factor' => (float) $profit_factor,
            'kelly_pct'     => (float) $kelly_pct,
        ]);
    }

    /**
     * Return closed trades ordered by exit_time for equity-curve rendering.
     * Each element: [ date, trade_r, cumulative_r, symbol ]
     */
    public function get_equity_curve( int $rule_id ): array {
        $has_exit_reason = $this->has_exit_reason_column();
        $select = $has_exit_reason
            ? "SELECT symbol, entry_time, exit_time, final_r, holding_days, exit_reason"
            : "SELECT symbol, entry_time, exit_time, final_r, holding_days";

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "{$select}
                 FROM {$this->signal_table}
                 WHERE rule_id = %d AND status = 'closed' AND final_r IS NOT NULL
                 ORDER BY exit_time ASC, entry_time ASC",
                $rule_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        $cum    = 0.0;
        $result = [];
        foreach ( $rows as $row ) {
            $r          = round( (float) $row['final_r'], 6 );
            $cum        = round( $cum + $r, 6 );
            $exit_date  = ( (int) $row['exit_time'] > 0 )
                ? wp_date( 'Y-m-d', (int) $row['exit_time'], wp_timezone() )
                : '';
            $entry_date = ( (int) $row['entry_time'] > 0 )
                ? wp_date( 'Y-m-d', (int) $row['entry_time'], wp_timezone() )
                : '';
            $exit_reason = (string) ( $row['exit_reason'] ?? '' );

            $result[] = [
                'date'         => $exit_date,
                'entry_date'   => $entry_date,
                'holding_days' => (int) $row['holding_days'],
                'trade_r'      => $r,
                'cumulative_r' => $cum,
                'symbol'       => strtoupper( (string) $row['symbol'] ),
                'exit_reason'  => $exit_reason,
                'exit_label'   => ExitEngine::reason_label( $exit_reason ),
            ];
        }

        return $result;
    }

    /**
     * Thống kê breakdown exit_reason cho một rule.
     * Trả về: [ 'stop_loss' => N, 'max_loss' => N, 'take_profit' => N, 'max_hold' => N, 'unknown' => N ]
     */
    public function get_exit_reason_breakdown( int $rule_id ): array {
        $known  = [ ExitEngine::REASON_STOP_LOSS, ExitEngine::REASON_MAX_LOSS, ExitEngine::REASON_TAKE_PROFIT, ExitEngine::REASON_MAX_HOLD ];
        $result = array_fill_keys( $known, 0 );
        $result['unknown'] = 0;

        if ( ! $this->has_exit_reason_column() ) {
            return $result;
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT exit_reason, COUNT(*) AS cnt
                 FROM {$this->signal_table}
                 WHERE rule_id = %d AND status = 'closed'
                 GROUP BY exit_reason",
                $rule_id
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            $reason = (string) ( $row['exit_reason'] ?? '' );
            $cnt    = (int) ( $row['cnt'] ?? 0 );
            if ( in_array( $reason, $known, true ) ) {
                $result[ $reason ] += $cnt;
            } else {
                $result['unknown'] += $cnt;
            }
        }

        return $result;
    }

    /**
     * Compute a composite 0–100 score for a rule's performance row.
     * Weights: Expectancy 40 pts | Winrate 20 pts | Profit Factor 20 pts | Kelly 20 pts
     */
    public static function compute_score( array $perf ): float {
        $expectancy    = (float) ( $perf['expectancy']    ?? 0 );
        $winrate       = (float) ( $perf['winrate']       ?? 0 );
        $profit_factor = (float) ( $perf['profit_factor'] ?? 0 );
        $kelly_pct     = (float) ( $perf['kelly_pct']     ?? 0 );

        $e_score  = min( 40.0, $expectancy * 15 );        // 2.67 R  → 40 pts
        $w_score  = min( 20.0, $winrate    * 35 );        // 57 %    → 20 pts
        $pf_score = ( $profit_factor > 1 )
            ? min( 20.0, ( $profit_factor - 1 ) * 10 )   // PF 3    → 20 pts
            : 0.0;
        $k_score  = min( 20.0, $kelly_pct  * 60 );        // 33 %    → 20 pts

        return max( 0.0, round( $e_score + $w_score + $pf_score + $k_score, 1 ) );
    }

    public static function score_badge( float $score ): string {
        if ( $score >= 65 ) return 'good';
        if ( $score >= 40 ) return 'neutral';
        return 'weak';
    }

    /**
     * P3 FIX: Cache kết quả SHOW COLUMNS để không query lại nhiều lần trong cùng request.
     */
    private function has_exit_reason_column(): bool {
        if ( $this->has_exit_reason_col === null ) {
            $this->has_exit_reason_col = (bool) $this->wpdb->get_var(
                "SHOW COLUMNS FROM {$this->signal_table} LIKE 'exit_reason'"
            );
        }
        return $this->has_exit_reason_col;
    }

    public function list_performance($rule_id = 0) {
        if ($rule_id > 0) {
            return $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT p.*, r.name AS rule_name FROM {$this->performance_table} p LEFT JOIN {$this->wpdb->prefix}lcni_recommend_rule r ON r.id = p.rule_id WHERE p.rule_id = %d",
                    (int) $rule_id
                ),
                ARRAY_A
            );
        }

        return $this->wpdb->get_results("SELECT p.*, r.name AS rule_name FROM {$this->performance_table} p LEFT JOIN {$this->wpdb->prefix}lcni_recommend_rule r ON r.id = p.rule_id ORDER BY p.rule_id DESC", ARRAY_A);
    }
}
