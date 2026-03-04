<?php

if (!defined('ABSPATH')) {
    exit;
}

class PerformanceCalculator {
    private $wpdb;
    private $signal_table;
    private $performance_table;

    public function __construct(wpdb $wpdb) {
        $this->wpdb = $wpdb;
        $this->signal_table = $wpdb->prefix . 'lcni_recommend_signal';
        $this->performance_table = $wpdb->prefix . 'lcni_recommend_performance';
    }

    public function refresh_all() {
        $rule_ids = $this->wpdb->get_col("SELECT DISTINCT rule_id FROM {$this->signal_table}");
        foreach ((array) $rule_ids as $rule_id) {
            $this->refresh_rule((int) $rule_id);
        }
    }

    public function refresh_rule($rule_id) {
        $closed = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT final_r, holding_days FROM {$this->signal_table} WHERE rule_id = %d AND status = 'closed' AND final_r IS NOT NULL",
                (int) $rule_id
            ),
            ARRAY_A
        );

        $total = count($closed);
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

            if ($r >= 0) {
                $win++;
                $sum_win_r += $r;
            } else {
                $lose++;
                $sum_loss_r += abs($r);
            }
        }

        $winrate = $total > 0 ? $win / $total : 0;
        $avg_r = $total > 0 ? $sum_r / $total : 0;
        $avg_hold = $total > 0 ? $sum_hold / $total : 0;
        $avg_win_r = $win > 0 ? $sum_win_r / $win : 0;
        $avg_loss_r = $lose > 0 ? $sum_loss_r / $lose : 0;
        $expectancy = ($winrate * $avg_win_r) - ((1 - $winrate) * $avg_loss_r);

        $this->wpdb->replace($this->performance_table, [
            'rule_id' => (int) $rule_id,
            'total_trades' => (int) $total,
            'win_trades' => (int) $win,
            'lose_trades' => (int) $lose,
            'avg_r' => (float) $avg_r,
            'winrate' => (float) $winrate,
            'expectancy' => (float) $expectancy,
            'max_r' => $max_r,
            'min_r' => $min_r,
            'avg_hold_days' => (float) $avg_hold,
        ]);
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
