<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_SeedScheduler {

    const BATCH_REQUESTS_PER_RUN = 5;
    const RATE_LIMIT_MICROSECONDS = 300000;

    public static function start_seed() {
        $seeded = self::create_seed_tasks();

        update_option('lcni_seed_paused', 'no');

        LCNI_DB::log_change('seed_started', sprintf('Seed initialized with %d tasks.', $seeded));

        return $seeded;
    }

    public static function create_seed_tasks() {
        $security_summary = LCNI_DB::collect_security_definitions();

        if (is_wp_error($security_summary)) {
            LCNI_DB::log_change('seed_failed', 'Cannot create seed tasks: security definitions unavailable.');

            return 0;
        }

        $symbols = LCNI_DB::get_all_symbols();
        $timeframes = self::get_seed_timeframes();

        if (empty($symbols) || empty($timeframes)) {
            LCNI_DB::log_change('seed_skipped', 'No symbols/timeframes available to create seed tasks.');

            return 0;
        }

        LCNI_SeedRepository::reset_tasks();

        return LCNI_SeedRepository::create_seed_tasks($symbols, $timeframes);
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

        $to = !empty($task['last_to_time']) ? (int) $task['last_to_time'] : time();
        $requests = 0;

        while ($requests < self::BATCH_REQUESTS_PER_RUN) {
            $requests++;

            $result = LCNI_HistoryFetcher::fetch($task['symbol'], $task['timeframe'], $to, LCNI_HistoryFetcher::DEFAULT_LIMIT);
            if (is_wp_error($result)) {
                LCNI_DB::log_change('seed_task_failed', sprintf('Task %d fetch failed for %s-%s: %s', (int) $task['id'], $task['symbol'], $task['timeframe'], $result->get_error_message()));
                LCNI_SeedRepository::update_progress((int) $task['id'], $to);

                return [
                    'status' => 'error',
                    'message' => $result->get_error_message(),
                    'task_id' => (int) $task['id'],
                ];
            }

            $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
            $oldest_event_time = isset($result['oldest_event_time']) ? (int) $result['oldest_event_time'] : 0;

            if (empty($rows) || $oldest_event_time <= 0 || $oldest_event_time >= $to) {
                LCNI_SeedRepository::mark_done((int) $task['id']);
                LCNI_DB::log_change('seed_task_done', sprintf('Task %d done for %s-%s.', (int) $task['id'], $task['symbol'], $task['timeframe']));

                return [
                    'status' => 'done',
                    'task_id' => (int) $task['id'],
                    'requests' => $requests,
                ];
            }

            LCNI_DB::upsert_ohlc_rows($rows);
            $to = max(1, $oldest_event_time - 1);
            LCNI_SeedRepository::update_progress((int) $task['id'], $to);

            usleep(self::RATE_LIMIT_MICROSECONDS);
        }

        return [
            'status' => 'running',
            'task_id' => (int) $task['id'],
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
}
