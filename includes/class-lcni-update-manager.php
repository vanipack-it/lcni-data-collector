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

        return [
            'enabled' => !empty($settings['enabled']),
            'interval_minutes' => 1,
            'run_after_time' => self::normalize_run_after_time($settings['run_after_time'] ?? '09:00'),
        ];
    }

    public static function save_settings($enabled, $run_after_time = '09:00') {
        $settings = [
            'enabled' => (bool) $enabled,
            'interval_minutes' => 1,
            'run_after_time' => self::normalize_run_after_time($run_after_time),
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

        if (!self::is_after_configured_run_time($settings['run_after_time'])) {
            return;
        }

        $status = self::get_status();
        $next_run_ts = isset($status['next_run_ts']) ? (int) $status['next_run_ts'] : 0;
        if ($next_run_ts > current_time('timestamp')) {
            return;
        }

        self::run_update('auto');
    }

    public static function run_update($mode = 'auto') {
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
        ];
        self::save_status($status);

        $result = LCNI_DB::collect_intraday_data();

        if (is_wp_error($result)) {
            $status['running'] = false;
            $status['ended_at'] = current_time('mysql');
            $status['error'] = $result->get_error_message();
            $status['message'] = 'Runtime update failed.';
            $status['next_run_ts'] = self::calculate_next_run_ts();
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

        $run_after_ts = self::build_run_after_timestamp($now, (string) ($settings['run_after_time'] ?? '09:00'));
        if ($now->getTimestamp() < $run_after_ts) {
            return $run_after_ts;
        }

        if (!lcni_is_trading_time($now)) {
            return lcni_get_next_trading_time($now)->getTimestamp();
        }

        $candidate = $now->modify('+1 minutes');

        return lcni_get_next_trading_time($candidate)->getTimestamp();
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
}
