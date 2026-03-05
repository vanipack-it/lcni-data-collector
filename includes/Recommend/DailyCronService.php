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
        $this->signal_repository->prune_invalid_signals();
        $this->refresh_open_positions();

        $active_rules = $this->rule_repository->get_active_rules();
        $now = current_datetime();
        $current_date = $now->format('Y-m-d');
        $current_time = $now->format('H:i');

        foreach ($active_rules as $rule) {
            $scan_time = sanitize_text_field((string) ($rule['scan_time'] ?? '18:00'));
            $last_scan_at = (int) ($rule['last_scan_at'] ?? 0);
            $last_scan_date = $last_scan_at > 0 ? wp_date('Y-m-d', $last_scan_at, wp_timezone()) : '';

            if ($current_time < $scan_time || $last_scan_date === $current_date) {
                continue;
            }

            $candidates = $this->scan_rule_candidates($rule, $current_date);

            $this->rule_repository->update_last_scan_at((int) $rule['id'], current_time('timestamp'));
            $this->rule_repository->log_rule_change((int) $rule['id'], 'cron_scanned', 'Cron quét rule theo lịch hằng ngày.', [
                'scan_time' => $scan_time,
                'scanned_at' => current_time('mysql'),
                'candidate_count' => count($candidates),
            ]);
        }

        $this->performance_calculator->refresh_all();
    }

    public function scan_rule_now($rule, $scan_from_date = '', $scan_to_date = '') {
        if (!is_array($rule) || (int) ($rule['id'] ?? 0) <= 0) {
            return 0;
        }

        $this->signal_repository->prune_invalid_signals();
        $scan_from = $this->parse_scan_date($scan_from_date, '00:00:00');
        $scan_to = $this->parse_scan_date($scan_to_date, '23:59:59');

        if ($scan_from !== null || $scan_to !== null) {
            $start = $scan_from !== null ? $scan_from : 0;
            $end = $scan_to !== null ? $scan_to : current_time('timestamp');

            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            $candidates = $this->scan_rule_candidates_by_window($rule, $start, $end);
        } else {
            $candidates = $this->scan_rule_candidates_latest($rule);
        }

        $this->rule_repository->update_last_scan_at((int) $rule['id'], current_time('timestamp'));
        $this->rule_repository->log_rule_change((int) $rule['id'], 'manual_scanned', 'Quét thủ công rule từ danh sách.', [
            'scanned_at' => current_time('mysql'),
            'candidate_count' => count($candidates),
        ]);

        $this->performance_calculator->refresh_all();

        return count($candidates);
    }

    public function refresh_open_positions_now() {
        $this->refresh_open_positions();
        $this->performance_calculator->refresh_all();
    }

    public function backfill_rule_history($rule) {
        $apply_from_date = sanitize_text_field((string) ($rule['apply_from_date'] ?? ''));
        if ($apply_from_date === '') {
            return 0;
        }

        $tz = wp_timezone();
        $start = new DateTimeImmutable($apply_from_date . ' 00:00:00', $tz);
        $end = current_datetime()->setTimezone($tz);

        if ($start > $end) {
            return 0;
        }

        $this->signal_repository->prune_invalid_signals();
        $candidates = $this->rule_repository->find_candidate_symbols_by_window($rule, $start->getTimestamp(), $end->getTimestamp());
        $created = 0;

        foreach ($candidates as $candidate) {
            $signal_id = $this->signal_repository->create_signal($rule, $candidate['symbol'], (int) $candidate['event_time'], (float) $candidate['close_price']);
            if ($signal_id > 0) {
                $created++;
            }
        }

        $this->rule_repository->log_rule_change((int) $rule['id'], 'history_scanned', 'Quét dữ liệu lịch sử theo ngày áp dụng.', [
            'apply_from_date' => $apply_from_date,
            'candidate_count' => count($candidates),
            'created_signals' => $created,
        ]);

        $this->performance_calculator->refresh_all();

        return $created;
    }

    private function resolve_scan_window($rule, $current_date) {
        $tz = wp_timezone();
        $day_start = new DateTimeImmutable($current_date . ' 00:00:00', $tz);
        $day_end = new DateTimeImmutable($current_date . ' 23:59:59', $tz);

        $apply_from_date = sanitize_text_field((string) ($rule['apply_from_date'] ?? ''));
        if ($apply_from_date !== '') {
            $apply_start = new DateTimeImmutable($apply_from_date . ' 00:00:00', $tz);
            if ($apply_start > $day_start) {
                $day_start = $apply_start;
            }
        }

        return [
            'start' => $day_start->getTimestamp(),
            'end' => $day_end->getTimestamp(),
        ];
    }

    private function scan_rule_candidates($rule, $current_date) {
        $window = $this->resolve_scan_window($rule, $current_date);
        $candidates = $this->rule_repository->find_candidate_symbols_by_window($rule, $window['start'], $window['end']);

        foreach ($candidates as $candidate) {
            $this->signal_repository->create_signal($rule, $candidate['symbol'], (int) $candidate['event_time'], (float) $candidate['close_price']);
        }

        return $candidates;
    }

    private function scan_rule_candidates_latest($rule) {
        $candidates = $this->rule_repository->find_candidate_symbols($rule);

        foreach ($candidates as $candidate) {
            $this->signal_repository->create_signal($rule, $candidate['symbol'], (int) $candidate['event_time'], (float) $candidate['close_price']);
        }

        return $candidates;
    }

    private function scan_rule_candidates_by_window($rule, $start_event_time, $end_event_time) {
        $candidates = $this->rule_repository->find_candidate_symbols_by_window($rule, $start_event_time, $end_event_time);

        foreach ($candidates as $candidate) {
            $this->signal_repository->create_signal($rule, $candidate['symbol'], (int) $candidate['event_time'], (float) $candidate['close_price']);
        }

        return $candidates;
    }

    private function parse_scan_date($date, $time_suffix) {
        $date = sanitize_text_field((string) $date);
        if ($date === '') {
            return null;
        }

        $tz = wp_timezone();
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time_suffix, $tz);
        if (!$dt) {
            return null;
        }

        return $dt->getTimestamp();
    }

    private function refresh_open_positions() {
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
