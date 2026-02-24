<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SeedScheduler {

    const BATCH_REQUESTS_PER_RUN = 5;
    const TASKS_PER_RUN = 10;
    const MAX_FAILED_ATTEMPTS = 3;
    const RATE_LIMIT_MICROSECONDS = 300000;

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

            return new WP_Error('no_symbols', 'Không có symbol trong database. Vui lòng sync securities trước.');
        }

        if (empty($timeframes)) {
            LCNI_DB::log_change('seed_skipped', 'No symbols/timeframes available to create seed tasks.');

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

        $tasks_per_run = self::get_tasks_per_run();
        $processed_tasks = 0;
        $done_tasks = 0;
        $skipped_tasks = 0;
        $failed_tasks = 0;
        $running_tasks = 0;
        $last_task_id = 0;
        $messages = [];

        while ($processed_tasks < $tasks_per_run) {
            $task = LCNI_SeedRepository::get_next_task();
            if (empty($task)) {
                break;
            }

            $processed_tasks++;
            $summary = self::run_single_task_batch($task);
            $last_task_id = (int) ($summary['task_id'] ?? 0);

            if (!empty($summary['message'])) {
                $messages[] = (string) $summary['message'];
            }

            $status = (string) ($summary['status'] ?? 'running');
            if ($status === 'done') {
                $done_tasks++;
            } elseif ($status === 'skipped') {
                $skipped_tasks++;
            } elseif ($status === 'error') {
                $failed_tasks++;
            } else {
                $running_tasks++;
            }
        }

        if ($processed_tasks === 0) {
            return [
                'status' => 'idle',
                'message' => 'No pending seed task.',
            ];
        }

        return [
            'status' => ($failed_tasks > 0 && $done_tasks === 0 && $running_tasks === 0 && $skipped_tasks === 0) ? 'warning' : 'running',
            'processed_tasks' => $processed_tasks,
            'done_tasks' => $done_tasks,
            'skipped_tasks' => $skipped_tasks,
            'failed_tasks' => $failed_tasks,
            'running_tasks' => $running_tasks,
            'task_id' => $last_task_id,
            'message' => implode(' | ', array_slice($messages, 0, 3)),
        ];
    }

    public static function get_tasks_per_run() {
        return max(1, (int) get_option('lcni_seed_tasks_per_run', self::TASKS_PER_RUN));
    }

    public static function get_max_failed_attempts() {
        return max(1, (int) get_option('lcni_seed_max_failed_attempts', self::MAX_FAILED_ATTEMPTS));
    }

    private static function run_single_task_batch($task) {
        $task_id = (int) ($task['id'] ?? 0);
        $symbol = (string) ($task['symbol'] ?? 'N/A');
        $timeframe = (string) ($task['timeframe'] ?? 'N/A');

        $seed_constraints = self::get_seed_constraints();
        $to = !empty($task['last_to_time']) ? (int) $task['last_to_time'] : (int) ($seed_constraints['to_time'] ?? current_time('timestamp'));
        $min_from = self::resolve_min_from_for_task($task, $seed_constraints, $to);
        $requests = 0;

        $batch_requests_per_run = self::get_batch_requests_per_run();
        $rate_limit_microseconds = self::get_rate_limit_microseconds();

        while ($requests < $batch_requests_per_run) {
            $requests++;

            if ($to <= $min_from) {
                LCNI_SeedRepository::mark_done($task_id);
                LCNI_DB::log_change('seed_task_done', sprintf('Task %d done for %s-%s at from_time boundary.', $task_id, $symbol, $timeframe));

                return [
                    'status' => 'done',
                    'task_id' => $task_id,
                    'requests' => max(1, $requests),
                ];
            }

            $result = LCNI_HistoryFetcher::fetch($symbol, $timeframe, $to, LCNI_HistoryFetcher::DEFAULT_LIMIT, $min_from);
            if (is_wp_error($result)) {
                $error_message = (string) $result->get_error_message();

                if (self::is_non_retryable_task_error($error_message)) {
                    LCNI_SeedRepository::mark_done($task_id);
                    LCNI_DB::log_change('seed_task_skipped', sprintf('Task %d skipped for %s-%s due to non-retryable error: %s', $task_id, $symbol, $timeframe, $error_message));

                    return [
                        'status' => 'skipped',
                        'message' => $error_message,
                        'task_id' => $task_id,
                    ];
                }

                LCNI_DB::log_change('seed_task_failed', sprintf('Task %d fetch failed for %s-%s: %s', $task_id, $symbol, $timeframe, $error_message));
                $failed_attempts = LCNI_SeedRepository::mark_failed($task_id, $to, $error_message);
                $max_failed_attempts = self::get_max_failed_attempts();

                if ($failed_attempts >= $max_failed_attempts) {
                    LCNI_SeedRepository::mark_done($task_id);
                    LCNI_DB::log_change('seed_task_skipped', sprintf('Task %d skipped for %s-%s after %d failed attempts.', $task_id, $symbol, $timeframe, $failed_attempts));

                    return [
                        'status' => 'skipped',
                        'message' => sprintf('Task skipped after %d failures: %s', $failed_attempts, $error_message),
                        'task_id' => $task_id,
                    ];
                }

                return [
                    'status' => 'error',
                    'message' => $error_message,
                    'task_id' => $task_id,
                ];
            }

            $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
            $oldest_event_time = isset($result['oldest_event_time']) ? (int) $result['oldest_event_time'] : 0;

            if (empty($rows) || $oldest_event_time <= 0 || $oldest_event_time >= $to) {
                LCNI_SeedRepository::mark_done($task_id);
                LCNI_DB::log_change('seed_task_done', sprintf('Task %d done for %s-%s.', $task_id, $symbol, $timeframe));

                return [
                    'status' => 'done',
                    'task_id' => $task_id,
                    'requests' => $requests,
                ];
            }

            $rows = self::filter_rows_by_from_time($rows, $min_from);
            if (!empty($rows)) {
                LCNI_DB::upsert_ohlc_rows($rows);
            }

            $to = max(1, $oldest_event_time - 1);

            if ($to <= $min_from) {
                LCNI_SeedRepository::mark_done($task_id);
                LCNI_DB::log_change('seed_task_done', sprintf('Task %d done for %s-%s (reached configured from_time).', $task_id, $symbol, $timeframe));

                return [
                    'status' => 'done',
                    'task_id' => $task_id,
                    'requests' => $requests,
                ];
            }

            LCNI_SeedRepository::update_progress($task_id, $to);

            usleep($rate_limit_microseconds);
        }

        return [
            'status' => 'running',
            'task_id' => $task_id,
            'requests' => $requests,
            'next_to' => $to,
        ];
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
