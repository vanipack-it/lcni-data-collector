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

                // Intraday slots: chỉ chạy trong ngày làm việc (T2-T6)
                $dow = (int) current_datetime()->format('N'); // 1=Mon..7=Sun
                $interval = absint( $rule['scan_interval_minutes'] ?? 0 );
                $is_intraday_slot = $interval > 0 && ! in_array( $scan_time, RuleRepository::ALLOWED_SCAN_TIMES, true );
                if ( $is_intraday_slot && $dow >= 6 ) {
                    continue; // bỏ qua cuối tuần cho intraday slots
                }

                $slot_timestamp = $this->parse_scan_date($current_date, $scan_time . ':00');
                if ($slot_timestamp === null) {
                    continue;
                }

                if ($last_scan_at >= $slot_timestamp) {
                    continue;
                }

                $candidates = $this->scan_rule_candidates($rule, $current_date);
                // D1 FIX: update last_scan_at = slot_timestamp (khong phai time())
                // Neu dung time(): last_scan_at > slot sau -> cac slot sau trong ngay bi skip
                // Dung slot_timestamp: moi slot co timestamp rieng -> check dung
                $last_scan_at = $slot_timestamp;
                $this->rule_repository->update_last_scan_at((int) $rule['id'], $last_scan_at);
                $this->rule_repository->log_rule_change((int) $rule['id'], 'cron_scanned', 'Cron quét chiến lược theo lịch hằng ngày.', [
                    'scan_time'       => $scan_time,
                    'scan_times'      => $scan_times,
                    'scanned_at'      => current_time('mysql'),
                    'candidate_count' => count($candidates),
                ]);
                // Debug log để truy vết khi mã đáp ứng điều kiện nhưng không vào signal
                if ( defined('WP_DEBUG') && WP_DEBUG ) {
                    error_log( sprintf(
                        '[LCNI Recommend] Cron scanned rule #%d "%s" at %s — %d candidates found',
                        (int)$rule['id'], $rule['name'] ?? '', $scan_time, count($candidates)
                    ) );
                }
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
            $end = $scan_to !== null ? $scan_to : time();

            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            $candidates = $this->scan_rule_candidates_by_window($rule, $start, $end);
        } else {
            $candidates = $this->scan_rule_candidates_latest($rule);
        }

        // D3 FIX: scan_rule_now chỉ update last_scan_at khi là latest-scan (không có date range)
        // Window scan không update → cron vẫn chạy đúng slot sau đó
        if ( $scan_from === null && $scan_to === null ) {
            $this->rule_repository->update_last_scan_at((int) $rule['id'], time());
        }

        // Đếm số signals thực sự được tạo (khác với candidate_count)
        // scan_rule_candidates_* trả về $candidates (array rows từ ohlc)
        // create_signal có thể reject do: duplicate, invalid_symbol, price=0
        // → cần đếm riêng để hiển thị đúng cho admin
        $created_count = 0;
        foreach ( $candidates as $c ) {
            // Kiểm tra xem signal có được tạo không bằng cách tra bảng signal
            global $wpdb;
            $signal_table = $wpdb->prefix . 'lcni_recommend_signal';
            $timeframe = strtoupper( (string) ( $rule['timeframe'] ?? '1D' ) );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$signal_table}
                 WHERE rule_id = %d AND symbol = %s AND entry_time = %d LIMIT 1",
                (int) $rule['id'],
                strtoupper( (string) $c['symbol'] ),
                (int) $c['event_time']
            ) );
            if ( $exists ) $created_count++;
        }

        $this->rule_repository->log_rule_change((int) $rule['id'], 'manual_scanned', 'Quét thủ công rule từ danh sách.', [
            'scanned_at'      => current_time('mysql'),
            'candidate_count' => count($candidates),
            'created_count'   => $created_count,
            'candidates'      => array_map( static fn($c) => $c['symbol'] . '@' . $c['event_time'], $candidates ),
        ]);

        // P1 FIX: chỉ refresh performance của rule này, không refresh toàn bộ
        $this->performance_calculator->refresh_all( [ (int) $rule['id'] ] );

        // Trả về candidate_count (backward compat) nhưng thêm created vào log
        return count($candidates);
    }

    public function refresh_open_positions_now() {
        $affected_rule_ids = $this->refresh_open_positions();
        // P1 FIX: chỉ refresh các rule có open signals vừa được cập nhật
        if ( ! empty( $affected_rule_ids ) ) {
            $this->performance_calculator->refresh_all( array_unique( $affected_rule_ids ) );
        }
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

        // P1 FIX: chỉ refresh rule vừa backfill
        $this->performance_calculator->refresh_all( [ (int) $rule['id'] ] );

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
        $candidates = $this->rule_repository->find_candidate_symbols($rule);
        $created    = 0;
        $new_signals = []; // gom signals mới để notify batch

        foreach ($candidates as $candidate) {
            $sid = $this->signal_repository->create_signal($rule, $candidate['symbol'], (int) $candidate['event_time'], (float) $candidate['close_price']);
            if ( $sid > 0 ) {
                $created++;
                $new_signals[] = [
                    'signal_id'   => $sid,
                    'symbol'      => $candidate['symbol'],
                    'entry_price' => (float) $candidate['close_price'],
                    'entry_time'  => (int)   $candidate['event_time'],
                ];
            }
        }

        // Gửi 1 email digest + 1 inbox notification sau khi scan xong rule
        if ( ! empty( $new_signals ) && $this->signal_repository->get_notifier() !== null ) {
            $this->signal_repository->get_notifier()->on_new_signals_batch( $rule, $new_signals );
        }

        if ( count($candidates) > 0 && $created === 0 && defined('WP_DEBUG') && WP_DEBUG ) {
            $symbols = array_column($candidates, 'symbol');
            error_log( sprintf(
                '[LCNI Recommend] Rule #%d "%s": %d candidates found but 0 signals created (possible: duplicate, invalid symbol, or price=0). Symbols: %s',
                (int)$rule['id'], $rule['name'] ?? '', count($candidates), implode(',', $symbols)
            ) );
        }

        return $candidates;
    }

    private function scan_rule_candidates_latest($rule) {
        $candidates = $this->rule_repository->find_candidate_symbols($rule);
        $created    = 0;
        $new_signals = [];

        foreach ($candidates as $candidate) {
            $sid = $this->signal_repository->create_signal($rule, $candidate['symbol'], (int) $candidate['event_time'], (float) $candidate['close_price']);
            if ( $sid > 0 ) {
                $created++;
                $new_signals[] = [
                    'signal_id'   => $sid,
                    'symbol'      => $candidate['symbol'],
                    'entry_price' => (float) $candidate['close_price'],
                    'entry_time'  => (int)   $candidate['event_time'],
                ];
            }
        }

        if ( ! empty( $new_signals ) && $this->signal_repository->get_notifier() !== null ) {
            $this->signal_repository->get_notifier()->on_new_signals_batch( $rule, $new_signals );
        }

        if ( count($candidates) > 0 && $created === 0 && defined('WP_DEBUG') && WP_DEBUG ) {
            $symbols = array_column($candidates, 'symbol');
            error_log( sprintf(
                '[LCNI Recommend] Rule #%d "%s" (latest scan): %d candidates, 0 signals created. Symbols: %s',
                (int)$rule['id'], $rule['name'] ?? '', count($candidates), implode(',', $symbols)
            ) );
        }

        return $candidates;
    }

    private function scan_rule_candidates_by_window($rule, $start_event_time, $end_event_time) {
        $candidates = $this->rule_repository->find_candidate_symbols_by_window($rule, $start_event_time, $end_event_time);
        $created    = 0;
        $new_signals = [];

        foreach ($candidates as $candidate) {
            $sid = $this->signal_repository->create_signal($rule, $candidate['symbol'], (int) $candidate['event_time'], (float) $candidate['close_price']);
            if ( $sid > 0 ) {
                $created++;
                $new_signals[] = [
                    'signal_id'   => $sid,
                    'symbol'      => $candidate['symbol'],
                    'entry_price' => (float) $candidate['close_price'],
                    'entry_time'  => (int)   $candidate['event_time'],
                ];
            }
        }

        if ( ! empty( $new_signals ) && $this->signal_repository->get_notifier() !== null ) {
            $this->signal_repository->get_notifier()->on_new_signals_batch( $rule, $new_signals );
        }

        return $candidates;
    }


    private function resolve_rule_scan_times($rule) {
        $scan_times_raw = sanitize_text_field((string) ($rule['scan_times'] ?? ''));
        if ($scan_times_raw === '') {
            $scan_times_raw = sanitize_text_field((string) ($rule['scan_time'] ?? '18:00'));
        }

        // Buoc 1: chi giu cac moc co dinh trong ALLOWED_SCAN_TIMES
        $scan_times = array_values(array_unique(array_filter(array_map('sanitize_text_field', explode(',', $scan_times_raw)))));
        $scan_times = array_values(array_filter($scan_times, static function ($scan_time) {
            return in_array($scan_time, RuleRepository::ALLOWED_SCAN_TIMES, true);
        }));

        if (empty($scan_times)) {
            $scan_times = ['18:00'];
        }

        // Buoc 2: merge intraday slots SAU khi da filter
        // Intraday slots khong qua ALLOWED_SCAN_TIMES filter - chung duoc tao tu generate_intraday_slots()
        $interval = absint( $rule['scan_interval_minutes'] ?? 0 );
        if ( $interval > 0 && in_array( $interval, RuleRepository::ALLOWED_INTRADAY_INTERVALS, true ) ) {
            $intraday   = $this->generate_intraday_slots( $interval );
            $scan_times = array_values( array_unique( array_merge( $scan_times, $intraday ) ) );
        }

        sort($scan_times);

        return $scan_times;
    }

    /**
     * Sinh ra danh sách mốc giờ HH:MM trong phiên giao dịch HOSE: 09:00 – 14:45
     * Cách nhau $interval phút, bỏ nghỉ trưa 11:30–13:00.
     */
    private function generate_intraday_slots( int $interval ): array {
        $slots      = [];
        $session_start = strtotime( '1970-01-01 09:00:00 UTC' );
        $session_end   = strtotime( '1970-01-01 14:45:00 UTC' );
        $lunch_start   = strtotime( '1970-01-01 11:30:00 UTC' );
        $lunch_end     = strtotime( '1970-01-01 13:00:00 UTC' );
        $step = $interval * 60;

        for ( $t = $session_start; $t <= $session_end; $t += $step ) {
            // Bỏ qua nghỉ trưa (11:30–13:00)
            if ( $t > $lunch_start && $t < $lunch_end ) {
                continue;
            }
            $slots[] = gmdate( 'H:i', $t );
        }

        return $slots;
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

    /** @return int[] Danh sách rule_ids có signal được xử lý */
    private function refresh_open_positions(): array {
        $open_signals      = $this->signal_repository->get_open_signals();
        $affected_rule_ids = [];

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
            // E2 FIX: holding_days thực (uncapped) dùng cho get_exit_reason
            // capped_holding_days chỉ dùng để lưu DB (không vượt max_hold_days)
            $holding_days        = max( 0, (int) floor( ( $latest_time - (int) $signal['entry_time'] ) / DAY_IN_SECONDS ) );
            $max_hold_days       = max( 1, (int) ( $rule['max_hold_days'] ?? 1 ) );
            $capped_holding_days = min( $holding_days, $max_hold_days );

            $this->signal_repository->update_open_signal_metrics( (int) $signal['id'], $current_price, $r_multiple, $position_state, $capped_holding_days );

            // Hook cho UserRuleEngine cập nhật user_signals mirror
            do_action( 'lcni_signal_updated', (int) $signal['id'], $current_price, $r_multiple, $position_state, $capped_holding_days );

            // Truyền holding_days thực (uncapped) để MAX_HOLD check chính xác
            $exit_reason = $this->exit_engine->get_exit_reason( $signal, $rule, $current_price, $r_multiple, $holding_days );

            if ( $exit_reason === '' ) {
                $affected_rule_ids[] = (int) $signal['rule_id'];
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
                    $exit_candle = $this->find_candle_at_or_after( $signal['symbol'], (string) ( $rule['timeframe'] ?? '1D' ), $should_exit_ts );
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
            // E1+D2 FIX: Dùng ExitEngine::compute_final_r() làm nguồn canonical
            // thay vì duplicate logic cap ở đây.
            $raw_r   = ( $exit_price - $entry_price ) / $risk_per_share;
            $final_r = round( ExitEngine::compute_final_r( $raw_r, $exit_reason ), 6 );

            $this->signal_repository->close_signal(
                (int) $signal['id'],
                $exit_price,
                $exit_time,
                $final_r,
                $capped_holding_days,
                $exit_reason
            );

            // Hook cho UserRuleEngine đóng user_signals + recalc performance
            do_action( 'lcni_signal_closed', (int) $signal['id'], $exit_price, $exit_time, $final_r, $capped_holding_days, $exit_reason );

            $affected_rule_ids[] = (int) $signal['rule_id'];
        }

        return array_unique( $affected_rule_ids );
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
