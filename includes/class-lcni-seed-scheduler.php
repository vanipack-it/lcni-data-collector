<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SeedScheduler {

    const BATCH_REQUESTS_PER_RUN = 5;
    const RATE_LIMIT_MICROSECONDS = 100000;
    const OPTION_BATCH_REQUESTS_PER_RUN = 'lcni_seed_batch_requests_per_run';
    const OPTION_RATE_LIMIT_MICROSECONDS = 'lcni_seed_rate_limit_microseconds';

    public static function start_seed($constraints = []) {
        $seeded = self::create_seed_tasks($constraints);

        if (is_wp_error($seeded)) {
            return $seeded;
        }

        update_option('lcni_seed_paused', 'no');

        return $seeded;
    }

    public static function create_seed_tasks($constraints = []) {
        $symbols = LCNI_DB::get_all_symbols();
        $timeframes = self::get_seed_timeframes();
        $seed_constraints = self::resolve_seed_constraints($constraints);

        if (empty($symbols)) {
            return new WP_Error('no_symbols', 'Không có symbol trong database. Vui lòng sync securities trước.');
        }

        if (empty($timeframes)) {
            return 0;
        }

        self::persist_seed_constraints($seed_constraints);

        LCNI_SeedRepository::reset_tasks();

        return LCNI_SeedRepository::create_seed_tasks($symbols, $timeframes, (int) $seed_constraints['to_time']);
    }

    public static function run_batch() {
        if (self::is_paused()) {
            return [
                'status' => 'paused',
                'message' => 'Seed queue is paused.',
            ];
        }

        $task = LCNI_SeedRepository::get_next_task();
        if (empty($task)) {
            return [
                'status' => 'idle',
                'message' => 'No pending seed task.',
            ];
        }

        $seed_constraints = self::get_seed_constraints();
        $to = !empty($task['last_to_time']) ? (int) $task['last_to_time'] : (int) ($seed_constraints['to_time'] ?? current_time('timestamp'));
        $min_from = self::resolve_min_from_for_task($task, $seed_constraints, $to);
        $requests = 0;

        $batch_requests_per_run = self::get_batch_requests_per_run();
        $rate_limit_microseconds = self::get_rate_limit_microseconds();

        while ($requests < $batch_requests_per_run) {
            $requests++;

            if ($to <= $min_from) {
                LCNI_SeedRepository::mark_done((int) $task['id']);

                return [
                    'status' => 'done',
                    'task_id' => (int) $task['id'],
                    'requests' => max(1, $requests),
                ];
            }

            $result = LCNI_HistoryFetcher::fetch($task['symbol'], $task['timeframe'], $to, LCNI_HistoryFetcher::DEFAULT_LIMIT, $min_from);
            if (is_wp_error($result)) {
                $error_message = (string) $result->get_error_message();

                if (self::is_non_retryable_task_error($error_message)) {
                    LCNI_SeedRepository::mark_done((int) $task['id']);

                    return [
                        'status' => 'skipped',
                        'message' => $error_message,
                        'task_id' => (int) $task['id'],
                    ];
                }

                LCNI_SeedRepository::mark_failed((int) $task['id'], $to, $error_message);

                usleep($rate_limit_microseconds);
                continue;
            }

            $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
            $oldest_event_time = isset($result['oldest_event_time']) ? (int) $result['oldest_event_time'] : 0;

            if (empty($rows) || $oldest_event_time <= 0 || $oldest_event_time >= $to) {
                LCNI_SeedRepository::mark_done((int) $task['id']);

                return [
                    'status' => 'done',
                    'task_id' => (int) $task['id'],
                    'requests' => $requests,
                ];
            }

            $rows = self::filter_rows_by_from_time($rows, $min_from);
            if (!empty($rows)) {
                LCNI_DB::upsert_ohlc_rows($rows);
            }

            $to = max(1, $oldest_event_time - 1);

            if ($to <= $min_from) {
                LCNI_SeedRepository::mark_done((int) $task['id']);

                return [
                    'status' => 'done',
                    'task_id' => (int) $task['id'],
                    'requests' => $requests,
                ];
            }

            LCNI_SeedRepository::update_progress((int) $task['id'], $to);

            usleep($rate_limit_microseconds);
        }

        return [
            'status' => 'running',
            'task_id' => (int) $task['id'],
            'requests' => $requests,
            'next_to' => $to,
        ];
    }


    public static function get_batch_requests_per_run() {
        return max(1, (int) get_option(self::OPTION_BATCH_REQUESTS_PER_RUN, self::BATCH_REQUESTS_PER_RUN));
    }

    public static function get_rate_limit_microseconds() {
        return max(0, (int) get_option(self::OPTION_RATE_LIMIT_MICROSECONDS, self::RATE_LIMIT_MICROSECONDS));
    }

    public static function save_runtime_settings($batch_requests_per_run, $rate_limit_microseconds) {
        update_option(self::OPTION_BATCH_REQUESTS_PER_RUN, max(1, (int) $batch_requests_per_run));
        update_option(self::OPTION_RATE_LIMIT_MICROSECONDS, max(0, (int) $rate_limit_microseconds));
    }

    public static function pause() {
        update_option('lcni_seed_paused', 'yes');
    }

    public static function resume() {
        update_option('lcni_seed_paused', 'no');
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
