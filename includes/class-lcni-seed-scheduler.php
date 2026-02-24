<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SeedScheduler {

    const BATCH_REQUESTS_PER_RUN = 5;
    const RATE_LIMIT_MICROSECONDS = 300000;
    const MAX_CANDLES_PER_SYMBOL = 500;
    const START_SEED_CRON_HOOK = 'lcni_seed_start_cron';
    const OPTION_PENDING_START = 'lcni_seed_pending_start_constraints';
    const OPTION_SYMBOL_OFFSET = 'lcni_seed_symbol_offset';

    public static function init() {
        add_action(self::START_SEED_CRON_HOOK, [__CLASS__, 'handle_scheduled_seed_start']);
    }

    public static function trigger_seed_start($constraints = []) {
        update_option(self::OPTION_PENDING_START, is_array($constraints) ? $constraints : []);

        if (!wp_next_scheduled(self::START_SEED_CRON_HOOK)) {
            wp_schedule_single_event(current_time('timestamp') + 5, self::START_SEED_CRON_HOOK);
        }

        return [
            'queued' => true,
            'message' => 'Seed queue đã được đưa vào tiến trình chạy nền.',
        ];
    }

    public static function handle_scheduled_seed_start() {
        $constraints = get_option(self::OPTION_PENDING_START, []);
        delete_option(self::OPTION_PENDING_START);

        self::start_seed(is_array($constraints) ? $constraints : []);
    }

    public static function start_seed($constraints = []) {
        $seeded = self::create_seed_tasks($constraints);

        if (is_wp_error($seeded)) {
            LCNI_DB::log_change('seed_failed', 'Seed initialization failed: ' . $seeded->get_error_message());

            return $seeded;
        }

        update_option('lcni_seed_paused', 'no');

        LCNI_DB::log_change('seed_started', sprintf('Seed initialized with %d tasks.', $seeded));

        return $seeded;
    }

    public static function create_seed_tasks($constraints = []) {
        $symbols = LCNI_DB::get_all_symbols();
        $timeframes = self::get_seed_timeframes();
        $seed_constraints = self::resolve_seed_constraints($constraints);

        if (empty($symbols)) {
            LCNI_DB::log_change('seed_skipped', 'Seed queue skipped: no symbols in database.');

            return new WP_Error('no_symbols', 'Không có symbol trong database. Vui lòng import bảng lcni_symbol_tongquan trước.');
        }

        if (empty($timeframes)) {
            LCNI_DB::log_change('seed_skipped', 'No symbols/timeframes available to create seed tasks.');

            return 0;
        }

        self::persist_seed_constraints($seed_constraints);

        $batch_symbols = self::slice_symbols_for_batch($symbols);
        if (empty($batch_symbols)) {
            return 0;
        }

        LCNI_SeedRepository::reset_tasks();

        $created = LCNI_SeedRepository::create_seed_tasks_for_symbols($batch_symbols, $timeframes, (int) $seed_constraints['to_time']);
        LCNI_DB::log_change('seed_batch_symbols', sprintf('Prepared %d symbols for this seed run (offset=%d).', count($batch_symbols), (int) get_option(self::OPTION_SYMBOL_OFFSET, 0)));

        return $created;
    }

    public static function run_batch() {
        $started_at = microtime(true);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $summary = [
            'status' => 'idle',
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'execution_time' => 0,
        ];

        if (self::is_paused()) {
            $summary['status'] = 'paused';
            $summary['message'] = 'Seed queue is paused.';

            return self::finalize_summary($summary, $started_at);
        }

        $task = LCNI_SeedRepository::get_next_task();
        if (empty($task)) {
            $summary['status'] = 'idle';
            $summary['message'] = 'No pending seed task.';

            return self::finalize_summary($summary, $started_at);
        }

        $seed_constraints = self::get_seed_constraints();
        $to = !empty($task['last_to_time']) ? (int) $task['last_to_time'] : (int) ($seed_constraints['to_time'] ?? current_time('timestamp'));
        $min_from = self::resolve_min_from_for_task($task, $seed_constraints, $to);
        $requests = 0;

        $batch_requests_per_run = self::get_batch_requests_per_run();
        $rate_limit_microseconds = self::get_rate_limit_microseconds();
        $max_candles_per_symbol = self::get_max_candles_per_symbol();

        while ($requests < $batch_requests_per_run) {
            $requests++;
            $summary['processed']++;

            if ($to <= $min_from) {
                LCNI_SeedRepository::mark_done((int) $task['id']);
                $summary['status'] = 'done';
                $summary['success']++;
                break;
            }

            try {
                $result = LCNI_HistoryFetcher::fetch($task['symbol'], $task['timeframe'], $to, $max_candles_per_symbol, $min_from);
                if (is_wp_error($result)) {
                    $error_message = (string) $result->get_error_message();

                    if (self::is_non_retryable_task_error($error_message)) {
                        LCNI_SeedRepository::mark_done((int) $task['id']);
                        $summary['status'] = 'skipped';
                        $summary['failed']++;
                        break;
                    }

                    LCNI_SeedRepository::mark_failed((int) $task['id'], $to, $error_message);
                    $summary['status'] = 'error';
                    $summary['failed']++;
                    break;
                }

                $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
                $oldest_event_time = isset($result['oldest_event_time']) ? (int) $result['oldest_event_time'] : 0;

                if (!empty($rows)) {
                    $rows = self::filter_rows_by_from_time($rows, $min_from);
                    if (count($rows) > $max_candles_per_symbol) {
                        $rows = array_slice($rows, -$max_candles_per_symbol);
                    }
                }

                if (empty($rows) || $oldest_event_time <= 0 || $oldest_event_time >= $to) {
                    LCNI_SeedRepository::mark_done((int) $task['id']);
                    $summary['status'] = 'done';
                    $summary['success']++;
                    break;
                }

                LCNI_DB::upsert_ohlc_rows($rows);
                $summary['success']++;

                $to = max(1, $oldest_event_time - 1);

                if ($to <= $min_from) {
                    LCNI_SeedRepository::mark_done((int) $task['id']);
                    $summary['status'] = 'done';
                    break;
                }

                LCNI_SeedRepository::update_progress((int) $task['id'], $to);
                $summary['status'] = 'running';
            } catch (Throwable $e) {
                error_log('[LCNI Seed] ' . $e->getMessage());
                LCNI_SeedRepository::mark_failed((int) $task['id'], $to, $e->getMessage());
                $summary['failed']++;
                $summary['status'] = 'error';
                continue;
            }

            usleep($rate_limit_microseconds);
        }

        $summary['task_id'] = (int) $task['id'];
        $summary['requests'] = $requests;

        return self::finalize_summary($summary, $started_at);
    }

    public static function pause() {
        update_option('lcni_seed_paused', 'yes');
        LCNI_DB::log_change('seed_paused', 'Seed queue paused from admin dashboard.');
    }

    public static function resume() {
        update_option('lcni_seed_paused', 'no');
        LCNI_DB::log_change('seed_resumed', 'Seed queue resumed from admin dashboard.');
    }

    public static function is_paused() {
        return get_option('lcni_seed_paused', 'no') === 'yes';
    }

    private static function get_seed_timeframes() {
        $raw = (string) get_option('lcni_seed_timeframes', '1D');
        $parts = preg_split('/[\s,]+/', strtoupper($raw));
        $parts = array_values(array_unique(array_filter(array_map('trim', (array) $parts))));

        return $parts;
    }

    public static function get_batch_requests_per_run() {
        return max(1, (int) get_option('lcni_seed_batch_requests_per_run', self::BATCH_REQUESTS_PER_RUN));
    }

    public static function get_rate_limit_microseconds() {
        return max(1, (int) get_option('lcni_seed_rate_limit_microseconds', self::RATE_LIMIT_MICROSECONDS));
    }

    public static function get_max_candles_per_symbol() {
        return max(1, (int) get_option('lcni_seed_max_candles_per_symbol', self::MAX_CANDLES_PER_SYMBOL));
    }

    public static function get_seed_constraints() {
        return [
            'mode' => (string) get_option('lcni_seed_range_mode', 'full'),
            'from_time' => max(1, (int) get_option('lcni_seed_from_time', 1)),
            'to_time' => max(1, (int) get_option('lcni_seed_to_time', current_time('timestamp'))),
            'sessions' => max(1, (int) get_option('lcni_seed_session_count', 300)),
        ];
    }

    private static function resolve_seed_constraints($constraints = []) {
        $mode = isset($constraints['mode']) ? sanitize_key((string) $constraints['mode']) : (string) get_option('lcni_seed_range_mode', 'full');
        if (!in_array($mode, ['full', 'date_range', 'sessions'], true)) {
            $mode = 'full';
        }

        $to_time = isset($constraints['to_time']) ? (int) $constraints['to_time'] : current_time('timestamp');
        $to_time = max(1, $to_time);
        $from_time = 1;
        $sessions = isset($constraints['sessions']) ? max(1, (int) $constraints['sessions']) : max(1, (int) get_option('lcni_seed_session_count', 300));

        if ($mode === 'date_range') {
            $from_time = isset($constraints['from_time']) ? max(1, (int) $constraints['from_time']) : 1;
            if ($from_time > $to_time) {
                $swap = $from_time;
                $from_time = $to_time;
                $to_time = $swap;
            }
        } elseif ($mode === 'sessions') {
            $seed_timeframes = self::get_seed_timeframes();
            $reference_tf = !empty($seed_timeframes[0]) ? $seed_timeframes[0] : '1D';
            $interval = LCNI_HistoryFetcher::timeframe_to_seconds($reference_tf);
            $from_time = max(1, $to_time - ($interval * $sessions));
        }

        return [
            'mode' => $mode,
            'from_time' => $from_time,
            'to_time' => $to_time,
            'sessions' => $sessions,
        ];
    }

    private static function finalize_summary($summary, $started_at) {
        $summary['execution_time'] = round(max(0, microtime(true) - (float) $started_at), 4);

        return $summary;
    }

    private static function slice_symbols_for_batch($symbols) {
        $symbols = array_values(array_filter(array_map('strval', (array) $symbols)));
        $total = count($symbols);
        if ($total === 0) {
            update_option(self::OPTION_SYMBOL_OFFSET, 0);

            return [];
        }

        $batch_size = self::get_batch_requests_per_run();
        $offset = max(0, (int) get_option(self::OPTION_SYMBOL_OFFSET, 0));
        if ($offset >= $total) {
            $offset = 0;
        }

        $batch_symbols = array_slice($symbols, $offset, $batch_size);
        if (empty($batch_symbols)) {
            $offset = 0;
            $batch_symbols = array_slice($symbols, 0, $batch_size);
        }

        $next_offset = $offset + count($batch_symbols);
        if ($next_offset >= $total) {
            $next_offset = 0;
        }
        update_option(self::OPTION_SYMBOL_OFFSET, $next_offset);

        return $batch_symbols;
    }

    private static function persist_seed_constraints($constraints) {
        update_option('lcni_seed_range_mode', (string) ($constraints['mode'] ?? 'full'));
        update_option('lcni_seed_from_time', (int) ($constraints['from_time'] ?? 1));
        update_option('lcni_seed_to_time', (int) ($constraints['to_time'] ?? current_time('timestamp')));
        update_option('lcni_seed_session_count', max(1, (int) ($constraints['sessions'] ?? 300)));
    }

    private static function resolve_min_from_for_task($task, $seed_constraints, $to_time) {
        $mode = (string) ($seed_constraints['mode'] ?? 'full');
        if ($mode === 'sessions') {
            $sessions = max(1, (int) ($seed_constraints['sessions'] ?? 300));
            $interval = LCNI_HistoryFetcher::timeframe_to_seconds((string) ($task['timeframe'] ?? '1D'));

            return max(1, (int) $to_time - ($interval * $sessions));
        }

        return max(1, (int) ($seed_constraints['from_time'] ?? 1));
    }

    private static function filter_rows_by_from_time($rows, $from_time) {
        if (empty($rows) || $from_time <= 1) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $event_time = isset($row['event_time']) ? (int) $row['event_time'] : self::parse_candle_time_to_timestamp((string) ($row['candle_time'] ?? ''));
            if ($event_time >= $from_time) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    private static function parse_candle_time_to_timestamp($candle_time) {
        $raw = trim((string) $candle_time);
        if ($raw === '') {
            return 0;
        }

        try {
            return (new DateTimeImmutable($raw, wp_timezone()))->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    private static function is_non_retryable_task_error($message) {
        $message = strtoupper(trim((string) $message));

        if ($message === '') {
            return false;
        }

        return strpos($message, 'HTTP 400') !== false;
    }
}
