<?php

if (!defined('ABSPATH')) {
    exit;
}

class DailyCronService {
    const CRON_HOOK = 'lcni_recommend_daily_cron';

    private $rule_repository;
    private $signal_repository;
    private $position_engine;
    private $exit_engine;
    private $performance_calculator;
    private $wpdb;

    public function __construct(RuleRepository $rule_repository, SignalRepository $signal_repository, PositionEngine $position_engine, ExitEngine $exit_engine, PerformanceCalculator $performance_calculator, wpdb $wpdb) {
        $this->rule_repository = $rule_repository;
        $this->signal_repository = $signal_repository;
        $this->position_engine = $position_engine;
        $this->exit_engine = $exit_engine;
        $this->performance_calculator = $performance_calculator;
        $this->wpdb = $wpdb;
    }

    public function run_daily() {
        $open_signals = $this->signal_repository->get_open_signals();

        foreach ($open_signals as $signal) {
            $rule = $this->rule_repository->find((int) $signal['rule_id']);
            if (!$rule) {
                continue;
            }

            $price_snapshot = $this->get_latest_price($signal['symbol'], $rule['timeframe']);
            if (!$price_snapshot) {
                continue;
            }

            $current_price = (float) $price_snapshot['close_price'];
            $r_multiple = $this->position_engine->calculate_r_multiple((float) $signal['entry_price'], (float) $signal['initial_sl'], $current_price);
            $position_state = $this->position_engine->resolve_state($r_multiple, (float) $rule['add_at_r'], (float) $rule['exit_at_r']);
            $holding_days = max(0, (int) floor(((int) $price_snapshot['event_time'] - (int) $signal['entry_time']) / DAY_IN_SECONDS));

            $this->signal_repository->update_open_signal_metrics((int) $signal['id'], $current_price, $r_multiple, $position_state, $holding_days);

            if ($this->exit_engine->should_exit($signal, $rule, $current_price, $r_multiple, $holding_days)) {
                $this->signal_repository->close_signal((int) $signal['id'], $current_price, (int) $price_snapshot['event_time'], $r_multiple, $holding_days);
            }
        }

        $active_rules = $this->rule_repository->get_active_rules();
        foreach ($active_rules as $rule) {
            $candidates = $this->rule_repository->find_candidate_symbols($rule);
            foreach ($candidates as $candidate) {
                $this->signal_repository->create_signal($rule, $candidate['symbol'], (int) $candidate['event_time'], (float) $candidate['close_price']);
            }
        }

        $this->performance_calculator->refresh_all();
    }

    private function get_latest_price($symbol, $timeframe) {
        $table = $this->wpdb->prefix . 'lcni_ohlc';

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT event_time, close_price FROM {$table} WHERE symbol = %s AND timeframe = %s ORDER BY event_time DESC LIMIT 1",
                strtoupper((string) $symbol),
                strtoupper((string) $timeframe)
            ),
            ARRAY_A
        );
    }
}
