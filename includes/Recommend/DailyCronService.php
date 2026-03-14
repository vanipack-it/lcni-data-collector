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
            $last_scan_at = (int) ($rule['last_scan_at'] ?? 0);
            $scan_times = $this->resolve_rule_scan_times($rule);

            foreach ($scan_times as $scan_time) {
                if ($current_time < $scan_time) {
                    continue;
                }

                $slot_timestamp = $this->parse_scan_date($current_date, $scan_time . ':00');
                if ($slot_timestamp === null) {
                    continue;
                }

                if ($last_scan_at >= $slot_timestamp) {
                    continue;
                }

                $candidates = $this->scan_rule_candidates($rule, $current_date);
                $last_scan_at = current_time('timestamp');
                $this->rule_repository->update_last_scan_at((int) $rule['id'], $last_scan_at);
                $this->rule_repository->log_rule_change((int) $rule['id'], 'cron_scanned', 'Cron quét rule theo lịch hằng ngày.', [
                    'scan_time' => $scan_time,
                    'scan_times' => $scan_times,
                    'scanned_at' => current_time('mysql'),
                    'candidate_count' => count($candidates),
                ]);
            }
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

    /**
     * Lấy nến tại hoặc ngay sau một timestamp (dùng để lấy exit_price đúng).
     */
    private function find_candle_at_or_after( string $symbol, string $timeframe, int $at_time ): ?array {
        $table = $this->wpdb->prefix . 'lcni_ohlc';
        $row   = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT event_time, close_price FROM {$table}
                 WHERE symbol = %s AND timeframe = %s AND event_time >= %d
                 ORDER BY event_time ASC LIMIT 1",
                $symbol, $timeframe, $at_time
            ), ARRAY_A
        );
        return $row ?: null;
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


    private function resolve_rule_scan_times($rule) {
        $scan_times_raw = sanitize_text_field((string) ($rule['scan_times'] ?? ''));
        if ($scan_times_raw === '') {
            $scan_times_raw = sanitize_text_field((string) ($rule['scan_time'] ?? '18:00'));
        }

        $scan_times = array_values(array_unique(array_filter(array_map('sanitize_text_field', explode(',', $scan_times_raw)))));
        $scan_times = array_values(array_filter($scan_times, static function ($scan_time) {
            return in_array($scan_time, RuleRepository::ALLOWED_SCAN_TIMES, true);
        }));

        if (empty($scan_times)) {
            $scan_times = ['18:00'];
        }

        sort($scan_times);

        return $scan_times;
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

            $current_price  = (float) $price_snapshot['close_price'];
            $latest_time    = (int) $price_snapshot['event_time'];
            $entry_price    = (float) $signal['entry_price'];
            $initial_sl     = (float) $signal['initial_sl'];
            $risk_per_share = max( 0.0001, (float) $signal['risk_per_share'] );

            $r_multiple     = $this->position_engine->calculate_r_multiple( $entry_price, $initial_sl, $current_price );
            $position_state = $this->position_engine->resolve_state( $r_multiple, (float) $rule['add_at_r'], (float) $rule['exit_at_r'] );
            $holding_days   = max( 0, (int) floor( ( $latest_time - (int) $signal['entry_time'] ) / DAY_IN_SECONDS ) );
            $max_hold_days  = max( 1, (int) ( $rule['max_hold_days'] ?? 1 ) );
            $capped_holding_days = min( $holding_days, $max_hold_days );

            $this->signal_repository->update_open_signal_metrics( (int) $signal['id'], $current_price, $r_multiple, $position_state, $capped_holding_days );

            $exit_reason = $this->exit_engine->get_exit_reason( $signal, $rule, $current_price, $r_multiple, $holding_days );

            if ( $exit_reason === '' ) {
                continue;
            }

            // ── Xác định exit_time và exit_price chính xác ───────────────
            $exit_time  = $latest_time;
            $exit_price = $current_price; // default: giá cron chạy

            if ( $exit_reason === ExitEngine::REASON_MAX_HOLD ) {
                // Thoát do hết thời gian → exit_time = entry + max_hold_days (không phải ngày cron chạy trễ)
                $tz             = wp_timezone();
                $entry_dt       = ( new DateTimeImmutable( '@' . (int) $signal['entry_time'] ) )->setTimezone( $tz );
                $should_exit_dt = $entry_dt->modify( '+' . $max_hold_days . ' days' );
                $should_exit_ts = $should_exit_dt->getTimestamp();
                if ( $should_exit_ts > 0 && $should_exit_ts <= $latest_time ) {
                    $exit_time = $should_exit_ts;
                    // Lấy close_price của nến tại ngày should_exit_ts
                    $exit_candle = $this->find_candle_at_or_after( $signal['symbol'], $timeframe, $should_exit_ts );
                    if ( $exit_candle ) {
                        $exit_price = (float) $exit_candle['close_price'];
                    }
                    $capped_holding_days = $max_hold_days;
                }
                error_log( sprintf(
                    '[LCNI DEBUG] MAX_HOLD symbol=%s entry_time=%d latest_time=%d should_exit_ts=%d exit_time=%d | entry_date=%s should_exit_date=%s exit_date=%s',
                    $signal['symbol'], (int)$signal['entry_time'], $latest_time, $should_exit_ts, $exit_time,
                    wp_date('Y-m-d', (int)$signal['entry_time'], wp_timezone()),
                    wp_date('Y-m-d', $should_exit_ts, wp_timezone()),
                    wp_date('Y-m-d', $exit_time, wp_timezone())
                ) );
            } elseif ( $exit_reason === ExitEngine::REASON_STOP_LOSS ) {
                // Tìm nến đầu tiên chạm SL (close_price <= initial_sl) sau entry_time
                // → dùng close_price của nến đó làm exit_price
                $first_sl_candle = $this->get_first_candle_below_price(
                    $signal['symbol'], $rule['timeframe'],
                    (float) $signal['initial_sl'],
                    (int) $signal['entry_time']
                );
                if ( $first_sl_candle ) {
                    $exit_time  = (int) $first_sl_candle['event_time'];
                    $exit_price = (float) $first_sl_candle['close_price'];
                }
            } elseif ( $exit_reason === ExitEngine::REASON_MAX_LOSS ) {
                // Tìm nến đầu tiên chạm max_loss_cut sau entry_time
                $max_loss_pct       = abs( (float) ( $rule['max_loss_pct'] ?? ( $rule['initial_sl_pct'] ?? 8 ) ) );
                $max_loss_cut_price = $entry_price * ( 1 - $max_loss_pct / 100 );
                $first_ml_candle    = $this->get_first_candle_below_price(
                    $signal['symbol'], $rule['timeframe'],
                    $max_loss_cut_price,
                    (int) $signal['entry_time']
                );
                if ( $first_ml_candle ) {
                    $exit_time  = (int) $first_ml_candle['event_time'];
                    $exit_price = (float) $first_ml_candle['close_price'];
                }
            } elseif ( $exit_reason === ExitEngine::REASON_TAKE_PROFIT ) {
                // Tìm nến đầu tiên đạt r_multiple >= exit_at_r sau entry_time
                $first_tp_candle = $this->get_first_candle_take_profit(
                    $signal['symbol'], $rule['timeframe'],
                    $entry_price, $initial_sl, (float) $rule['exit_at_r'],
                    (int) $signal['entry_time']
                );
                if ( $first_tp_candle ) {
                    $exit_time  = (int) $first_tp_candle['event_time'];
                    $exit_price = (float) $first_tp_candle['close_price'];
                }
            }

            // ── Tính final_r chính xác theo exit_reason ───────────────────
            $final_r = ( $exit_price - $entry_price ) / $risk_per_share;

            if ( $exit_reason === ExitEngine::REASON_STOP_LOSS ) {
                // Giá gap qua SL → cap final_r = -1.0R (tối đa rủi ro đã chấp nhận)
                $sl_r    = ( $initial_sl - $entry_price ) / $risk_per_share; // thường = -1.0
                $final_r = max( $final_r, $sl_r );                           // lấy giá trị lớn hơn (ít lỗ hơn)
            } elseif ( $exit_reason === ExitEngine::REASON_MAX_LOSS ) {
                // Thoát do max_loss_cut → cap tại đúng max_loss_pct
                $max_loss_pct   = abs( (float) ( $rule['max_loss_pct'] ?? ( $rule['initial_sl_pct'] ?? 8 ) ) );
                $max_loss_price = $entry_price * ( 1 - $max_loss_pct / 100 );
                $max_loss_r     = ( $max_loss_price - $entry_price ) / $risk_per_share;
                $final_r        = max( $final_r, $max_loss_r );
            }

            $final_r = round( $final_r, 6 );

            $this->signal_repository->close_signal(
                (int) $signal['id'],
                $exit_price,
                $exit_time,
                $final_r,
                $capped_holding_days,
                $exit_reason
            );
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

    /**
     * Tìm nến đầu tiên (sau entry_time) có close_price <= threshold_price.
     * Dùng cho REASON_STOP_LOSS và REASON_MAX_LOSS.
     */
    private function get_first_candle_below_price( string $symbol, string $timeframe, float $threshold_price, int $after_time ): ?array {
        $table = $this->wpdb->prefix . 'lcni_ohlc';

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT event_time, close_price FROM {$table}
                 WHERE symbol = %s AND timeframe = %s
                   AND event_time > %d
                   AND close_price <= %f
                 ORDER BY event_time ASC LIMIT 1",
                strtoupper( $symbol ),
                strtoupper( $timeframe ),
                $after_time,
                $threshold_price
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Tìm nến đầu tiên (sau entry_time) đạt r_multiple >= exit_at_r.
     * Dùng cho REASON_TAKE_PROFIT.
     */
    private function get_first_candle_take_profit( string $symbol, string $timeframe, float $entry_price, float $initial_sl, float $exit_at_r, int $after_time ): ?array {
        $risk = $entry_price - $initial_sl;
        if ( abs( $risk ) < 0.0001 ) {
            return null;
        }

        // close_price >= entry_price + exit_at_r * risk
        $tp_price = $entry_price + $exit_at_r * $risk;
        $table    = $this->wpdb->prefix . 'lcni_ohlc';

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT event_time, close_price FROM {$table}
                 WHERE symbol = %s AND timeframe = %s
                   AND event_time > %d
                   AND close_price >= %f
                 ORDER BY event_time ASC LIMIT 1",
                strtoupper( $symbol ),
                strtoupper( $timeframe ),
                $after_time,
                $tp_price
            ),
            ARRAY_A
        ) ?: null;
    }
}
