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
            'interval_minutes' => max(1, (int) ($settings['interval_minutes'] ?? 5)),
        ];
    }

    public static function save_settings($enabled, $interval_minutes) {
        $settings = [
            'enabled' => (bool) $enabled,
            'interval_minutes' => max(1, (int) $interval_minutes),
        ];

        update_option(self::OPTION_SETTINGS, $settings);
        self::ensure_cron();

        return $settings;
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'lcni_every_minute', self::CRON_HOOK);
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

        $status = self::get_status();
        $next_run_ts = isset($status['next_run_ts']) ? (int) $status['next_run_ts'] : 0;
        if ($next_run_ts > time()) {
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
            'pending_symbols' => 0,
            'total_symbols' => 0,
            'changed_symbols' => 0,
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
        $status['pending_symbols'] = (int) ($result['pending_symbols'] ?? 0);
        $status['total_symbols'] = (int) ($result['total_symbols'] ?? 0);
        $status['changed_symbols'] = (int) ($result['changed_symbols'] ?? 0);
        $status['indicators_done'] = !empty($result['indicators_done']);
        $status['message'] = (string) ($result['message'] ?? 'Runtime update completed.');
        $status['error'] = '';
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

    private static function calculate_next_run_ts() {
        $settings = self::get_settings();

        return time() + (max(1, (int) $settings['interval_minutes']) * MINUTE_IN_SECONDS);
    }
}
