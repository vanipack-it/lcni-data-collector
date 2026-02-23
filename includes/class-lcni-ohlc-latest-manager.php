<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_OHLC_Latest_Manager {

    const OPTION_SETTINGS = 'lcni_ohlc_latest_runtime_settings';
    const OPTION_STATUS = 'lcni_ohlc_latest_runtime_status';
    const OPTION_STATS = 'lcni_snapshot_stats';
    const CRON_HOOK = 'lcni_ohlc_latest_snapshot_cron';
    const WATCHDOG_HOOK = 'lcni_ohlc_latest_watchdog_cron';
    const LOCK_TRANSIENT = 'lcni_ohlc_latest_snapshot_lock';

    public static function init() {
        add_action('init', [__CLASS__, 'ensure_infrastructure']);
        add_action('init', [__CLASS__, 'ensure_cron_health']);
        add_action(self::CRON_HOOK, [__CLASS__, 'handle_scheduled_sync']);
        add_action(self::WATCHDOG_HOOK, [__CLASS__, 'watchdog_check']);
    }

    public static function ensure_infrastructure() {
        $settings = self::get_settings();
        LCNI_DB::ensure_ohlc_latest_snapshot_infrastructure($settings['enabled'], $settings['interval_minutes']);
    }

    public static function ensure_cron_health() {
        $settings = self::get_settings();

        if (!$settings['enabled']) {
            self::clear_scheduled_hooks();
            return;
        }

        $snapshot_timestamp = wp_next_scheduled(self::CRON_HOOK);
        if (!$snapshot_timestamp) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'lcni_every_minute', self::CRON_HOOK);
        }

        $watchdog_timestamp = wp_next_scheduled(self::WATCHDOG_HOOK);
        if (!$watchdog_timestamp) {
            wp_schedule_event(time() + (2 * MINUTE_IN_SECONDS), 'lcni_every_minute', self::WATCHDOG_HOOK);
        }
    }

    public static function handle_scheduled_sync() {
        $settings = self::get_settings();
        if (!$settings['enabled']) {
            return;
        }

        if (!self::is_snapshot_stale($settings['interval_minutes'])) {
            return;
        }

        self::run_snapshot_sync('wp_cron');
    }

    public static function watchdog_check() {
        $settings = self::get_settings();
        if (!$settings['enabled']) {
            return;
        }

        $watchdog_marker = get_transient('lcni_ohlc_watchdog_5min_marker');
        if ($watchdog_marker) {
            return;
        }

        set_transient('lcni_ohlc_watchdog_5min_marker', '1', 5 * MINUTE_IN_SECONDS);

        if (!self::is_snapshot_stale($settings['interval_minutes'] * 2)) {
            return;
        }

        self::run_snapshot_sync('watchdog');
    }

    public static function get_settings() {
        $settings = get_option(self::OPTION_SETTINGS, []);

        return [
            'enabled' => !empty($settings['enabled']),
            'interval_minutes' => max(1, (int) ($settings['interval_minutes'] ?? 5)),
        ];
    }

    public static function get_status() {
        $status = get_option(self::OPTION_STATUS, []);

        return is_array($status) ? $status : [];
    }

    public static function get_snapshot_stats() {
        $stats = get_option(self::OPTION_STATS, []);

        return is_array($stats) ? $stats : [];
    }

    public static function get_engine_status() {
        $settings = self::get_settings();
        $last_snapshot_timestamp = self::get_last_snapshot_timestamp();
        $next_run = wp_next_scheduled(self::CRON_HOOK);

        return [
            'enabled' => $settings['enabled'],
            'interval_minutes' => $settings['interval_minutes'],
            'wp_cron_scheduled' => (bool) $next_run,
            'next_run' => $next_run ? get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $next_run), 'Y-m-d H:i:s') : '',
            'next_run_timestamp' => (int) $next_run,
            'last_snapshot_time' => $last_snapshot_timestamp > 0 ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $last_snapshot_timestamp), 'Y-m-d H:i:s') : '',
            'last_snapshot_timestamp' => $last_snapshot_timestamp,
            'event_scheduler' => LCNI_DB::get_mysql_event_scheduler_status(),
        ];
    }

    public static function save_settings($enabled, $interval_minutes) {
        $settings = [
            'enabled' => (bool) $enabled,
            'interval_minutes' => max(1, (int) $interval_minutes),
        ];

        update_option(self::OPTION_SETTINGS, $settings);
        update_option('lcni_ohlc_latest_enabled', (int) $settings['enabled']);
        update_option('lcni_ohlc_latest_interval_minutes', (int) $settings['interval_minutes']);

        LCNI_DB::ensure_ohlc_latest_snapshot_infrastructure($settings['enabled'], $settings['interval_minutes']);
        self::ensure_cron_health();

        return $settings;
    }

    public static function trigger_manual_sync() {
        return self::run_snapshot_sync('manual');
    }

    public static function reset_cron() {
        self::clear_scheduled_hooks();
        self::ensure_cron_health();
    }

    public static function force_full_rebuild() {
        $settings = self::get_settings();
        LCNI_DB::ensure_ohlc_latest_snapshot_infrastructure($settings['enabled'], $settings['interval_minutes']);

        return self::run_snapshot_sync('full_rebuild');
    }

    public static function recreate_mysql_event() {
        $settings = self::get_settings();
        LCNI_DB::ensure_ohlc_latest_snapshot_infrastructure($settings['enabled'], $settings['interval_minutes']);
    }

    private static function run_snapshot_sync($source) {
        if (!self::acquire_lock()) {
            return [
                'running' => false,
                'rows_affected' => 0,
                'message' => 'Snapshot sync skipped: another run is in progress.',
                'error' => '',
            ];
        }

        $started_at_timestamp = current_time('timestamp', true);
        $started_at = current_time('mysql');
        $status = [
            'running' => true,
            'source' => sanitize_key($source),
            'started_at' => $started_at,
            'ended_at' => '',
            'rows_affected' => 0,
            'message' => 'Running OHLC latest snapshot sync...',
            'error' => '',
        ];

        update_option(self::OPTION_STATUS, $status);

        $result = LCNI_DB::refresh_ohlc_latest_snapshot();
        $ended_at_timestamp = current_time('timestamp', true);

        $status['running'] = false;
        $status['ended_at'] = current_time('mysql');
        $status['rows_affected'] = (int) ($result['rows_affected'] ?? 0);
        $status['error'] = (string) ($result['error'] ?? '');
        $status['message'] = empty($status['error']) ? 'OHLC latest snapshot sync completed.' : 'OHLC latest snapshot sync failed.';
        update_option(self::OPTION_STATUS, $status);

        self::update_snapshot_stats($status, $started_at_timestamp, $ended_at_timestamp);
        self::release_lock();

        return $status;
    }

    private static function update_snapshot_stats($status, $started_at_timestamp, $ended_at_timestamp) {
        global $wpdb;

        $latest_table = $wpdb->prefix . 'lcni_ohlc_latest';
        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';

        $total_symbols = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$latest_table}");

        $market_stats = [
            'up' => 0,
            'down' => 0,
            'flat' => 0,
        ];

        $movement_row = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN l.close_price > p.prev_close THEN 1 ELSE 0 END) AS up_count,
                SUM(CASE WHEN l.close_price < p.prev_close THEN 1 ELSE 0 END) AS down_count,
                SUM(CASE WHEN l.close_price = p.prev_close THEN 1 ELSE 0 END) AS flat_count
            FROM {$latest_table} l
            LEFT JOIN (
                SELECT o.symbol, o.timeframe, o.event_time,
                    (
                        SELECT p2.close_price
                        FROM {$ohlc_table} p2
                        WHERE p2.symbol = o.symbol
                            AND p2.timeframe = o.timeframe
                            AND p2.event_time < o.event_time
                        ORDER BY p2.event_time DESC
                        LIMIT 1
                    ) AS prev_close
                FROM {$ohlc_table} o
                INNER JOIN (
                    SELECT symbol, timeframe, MAX(event_time) AS max_time
                    FROM {$ohlc_table}
                    GROUP BY symbol, timeframe
                ) t ON t.symbol = o.symbol AND t.timeframe = o.timeframe AND t.max_time = o.event_time
            ) p ON p.symbol = l.symbol AND p.timeframe = l.timeframe",
            ARRAY_A
        );

        if (is_array($movement_row)) {
            $market_stats['up'] = (int) ($movement_row['up_count'] ?? 0);
            $market_stats['down'] = (int) ($movement_row['down_count'] ?? 0);
            $market_stats['flat'] = (int) ($movement_row['flat_count'] ?? 0);
        }

        $next_run_timestamp = wp_next_scheduled(self::CRON_HOOK);
        $duration_seconds = max(0, (int) $ended_at_timestamp - (int) $started_at_timestamp);

        $stats = [
            'last_run' => current_time('mysql'),
            'last_run_timestamp' => (int) $ended_at_timestamp,
            'rows_affected' => (int) ($status['rows_affected'] ?? 0),
            'total_symbols' => $total_symbols,
            'next_run' => $next_run_timestamp ? get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $next_run_timestamp), 'Y-m-d H:i:s') : '',
            'next_run_timestamp' => (int) $next_run_timestamp,
            'duration_seconds' => $duration_seconds,
            'market_stats' => $market_stats,
        ];

        update_option(self::OPTION_STATS, $stats);
    }

    private static function acquire_lock() {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }

        return set_transient(self::LOCK_TRANSIENT, '1', 10 * MINUTE_IN_SECONDS);
    }

    private static function release_lock() {
        delete_transient(self::LOCK_TRANSIENT);
    }

    private static function clear_scheduled_hooks() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::WATCHDOG_HOOK);
    }

    private static function is_snapshot_stale($stale_interval_minutes) {
        $last_snapshot_timestamp = self::get_last_snapshot_timestamp();
        if ($last_snapshot_timestamp <= 0) {
            return true;
        }

        $age_seconds = current_time('timestamp', true) - $last_snapshot_timestamp;

        return $age_seconds > (max(1, (int) $stale_interval_minutes) * MINUTE_IN_SECONDS);
    }

    private static function get_last_snapshot_timestamp() {
        global $wpdb;

        $latest_table = $wpdb->prefix . 'lcni_ohlc_latest';
        $event_time = $wpdb->get_var("SELECT MAX(event_time) FROM {$latest_table}");
        if ($event_time === null) {
            return 0;
        }

        $event_time_int = (int) $event_time;
        if ($event_time_int <= 0) {
            return 0;
        }

        if ($event_time_int > 9999999999) {
            $event_time_int = (int) floor($event_time_int / 1000);
        }

        return $event_time_int;
    }
}
