<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Update_Manager {

    const OPTION_SETTINGS = 'lcni_update_runtime_settings';
    const OPTION_STATUS = 'lcni_update_runtime_status';
    const CRON_HOOK = 'lcni_runtime_update_cron';

    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'handle_cron']);
        add_action('init', [__CLASS__, 'ensure_cron']);
    }

    public static function get_settings() {
        $settings = get_option(self::OPTION_SETTINGS, []);
        $refresh_times = self::normalize_refresh_times($settings['refresh_times'] ?? []);
        if (empty($refresh_times)) {
            $refresh_times = self::normalize_refresh_times($settings['run_after_time'] ?? '09:00');
        }

        return [
            'enabled' => !empty($settings['enabled']),
            'interval_minutes' => 1,
            'run_after_time' => (string) ($refresh_times[0] ?? '09:00'),
            'refresh_times' => $refresh_times,
        ];
    }

    public static function save_settings($enabled, $refresh_times = ['09:00']) {
        $normalized_times = self::normalize_refresh_times($refresh_times);
        $settings = [
            'enabled' => (bool) $enabled,
            'interval_minutes' => 1,
            'run_after_time' => (string) ($normalized_times[0] ?? '09:00'),
            'refresh_times' => $normalized_times,
        ];

        update_option(self::OPTION_SETTINGS, $settings);
        self::ensure_cron();

        return $settings;
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(current_time('timestamp') + MINUTE_IN_SECONDS, 'lcni_every_minute', self::CRON_HOOK);
        }
    }

    public static function clear_running_state($message = 'Stopped by manual trigger.') {
        $status = self::get_status();
        $status['running'] = false;
        $status['ended_at'] = current_time('mysql');
        $status['message'] = $message;
        $status['error'] = '';
        self::save_status($status);
    }

    public static function trigger_manual_update() {
        self::clear_running_state('Previous run stopped. Manual update started.');

        return self::run_update('manual');
    }

    public static function handle_cron() {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        $current_slot = self::get_current_slot_key($settings['refresh_times']);
        if ($current_slot === '') {
            return;
        }

        $status = self::get_status();
        if (($status['last_auto_run_slot'] ?? '') === $current_slot) {
            return;
        }

        self::run_update('auto', $current_slot);
    }

    public static function run_update($mode = 'auto', $scheduled_slot = '') {
        $started_at = current_time('mysql');
        $status = [
            'running' => true,
            'mode' => $mode,
            'started_at' => $started_at,
            'ended_at' => '',
            'processed_symbols' => 0,
            'success_symbols' => 0,
            'error_symbols' => 0,
            'pending_symbols' => 0,
            'total_symbols' => 0,
            'changed_symbols' => 0,
            'execution_seconds' => 0,
            'indicators_done' => false,
            'message' => '',
            'error' => '',
            'next_run_ts' => 0,
            'last_auto_run_slot' => '',
        ];
        self::save_status($status);

        $result = LCNI_DB::collect_intraday_data();

        if (is_wp_error($result)) {
            $status['running'] = false;
            $status['ended_at'] = current_time('mysql');
            $status['error'] = $result->get_error_message();
            $status['message'] = 'Runtime update failed.';
            $status['next_run_ts'] = self::calculate_next_run_ts();
            if ($mode === 'auto' && $scheduled_slot !== '') {
                $status['last_auto_run_slot'] = $scheduled_slot;
            }
            self::save_status($status);

            return $status;
        }

        $status['running'] = false;
        $status['ended_at'] = current_time('mysql');
        $status['processed_symbols'] = (int) ($result['processed_symbols'] ?? 0);
        $status['success_symbols'] = (int) ($result['success_symbols'] ?? 0);
        $status['error_symbols'] = (int) ($result['error_symbols'] ?? 0);
        $status['pending_symbols'] = (int) ($result['pending_symbols'] ?? 0);
        $status['total_symbols'] = (int) ($result['total_symbols'] ?? 0);
        $status['changed_symbols'] = (int) ($result['changed_symbols'] ?? 0);
        $status['execution_seconds'] = (int) ($result['execution_seconds'] ?? 0);
        $status['indicators_done'] = !empty($result['indicators_done']);
        $status['waiting_for_trading_session'] = !empty($result['waiting_for_trading_session']);
        $status['message'] = (string) ($result['message'] ?? 'Runtime update completed.');
        $status['error'] = !empty($result['error']) ? (string) $result['error'] : '';
        $status['next_run_ts'] = self::calculate_next_run_ts();
        if ($mode === 'auto' && $scheduled_slot !== '') {
            $status['last_auto_run_slot'] = $scheduled_slot;
        }

        self::save_status($status);

        return $status;
    }

    public static function get_status() {
        $status = get_option(self::OPTION_STATUS, []);

        return is_array($status) ? $status : [];
    }

    public static function save_status($status) {
        update_option(self::OPTION_STATUS, $status);
    }

    public static function get_runtime_diagnostics() {
        $wp_timezone = wp_timezone();
        $market_timezone = lcni_get_market_timezone();
        $now = new DateTimeImmutable('now', $market_timezone);

        return [
            'wordpress_timezone' => $wp_timezone->getName(),
            'market_timezone' => $market_timezone->getName(),
            'server_timezone' => (string) date_default_timezone_get(),
            'current_time_mysql' => (string) current_time('mysql'),
            'current_time_timestamp' => (int) current_time('timestamp'),
            'is_trading_time' => lcni_is_trading_time($now),
        ];
    }

    private static function calculate_next_run_ts() {
        $settings = self::get_settings();
        $now = new DateTimeImmutable('now', lcni_get_market_timezone());
        $refresh_times = self::normalize_refresh_times($settings['refresh_times'] ?? []);

        foreach ($refresh_times as $time) {
            $candidate = self::build_run_after_timestamp($now, $time);
            if ($candidate > $now->getTimestamp()) {
                return $candidate;
            }
        }

        $tomorrow = $now->modify('+1 day');

        return self::build_run_after_timestamp($tomorrow, (string) ($refresh_times[0] ?? '09:00'));
    }

    private static function normalize_run_after_time($value) {
        $time = trim((string) $value);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return $time;
        }

        return '09:00';
    }

    private static function build_run_after_timestamp(DateTimeImmutable $now, $run_after_time) {
        $parts = explode(':', self::normalize_run_after_time($run_after_time));
        $hour = (int) ($parts[0] ?? 9);
        $minute = (int) ($parts[1] ?? 0);

        return (int) $now->setTime($hour, $minute, 0)->getTimestamp();
    }

    private static function is_after_configured_run_time($run_after_time) {
        $now = new DateTimeImmutable('now', lcni_get_market_timezone());

        return $now->getTimestamp() >= self::build_run_after_timestamp($now, $run_after_time);
    }

    private static function normalize_refresh_times($value) {
        $raw_values = is_array($value) ? $value : preg_split('/[\s,;|]+/', (string) $value);
        $normalized = [];

        foreach ((array) $raw_values as $item) {
            $time = trim((string) $item);
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                continue;
            }
            $normalized[$time] = $time;
        }

        if (empty($normalized)) {
            $normalized['09:00'] = '09:00';
        }

        $times = array_values($normalized);
        sort($times, SORT_STRING);

        return $times;
    }

    private static function get_current_slot_key($refresh_times) {
        $now = new DateTimeImmutable('now', lcni_get_market_timezone());
        $current_time = $now->format('H:i');

        foreach (self::normalize_refresh_times($refresh_times) as $time) {
            if ($time === $current_time) {
                return $now->format('Y-m-d') . ' ' . $time;
            }
        }

        return '';
    }
}
