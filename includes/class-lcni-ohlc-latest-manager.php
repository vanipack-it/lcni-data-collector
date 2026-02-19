<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_OHLC_Latest_Manager {

    const OPTION_SETTINGS = 'lcni_ohlc_latest_runtime_settings';
    const OPTION_STATUS = 'lcni_ohlc_latest_runtime_status';

    public static function init() {
        add_action('init', [__CLASS__, 'ensure_infrastructure']);
    }

    public static function ensure_infrastructure() {
        $settings = self::get_settings();
        LCNI_DB::ensure_ohlc_latest_snapshot_infrastructure($settings['enabled'], $settings['interval_minutes']);
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

    public static function save_settings($enabled, $interval_minutes) {
        $settings = [
            'enabled' => (bool) $enabled,
            'interval_minutes' => max(1, (int) $interval_minutes),
        ];

        update_option(self::OPTION_SETTINGS, $settings);
        update_option('lcni_ohlc_latest_enabled', (int) $settings['enabled']);
        update_option('lcni_ohlc_latest_interval_minutes', (int) $settings['interval_minutes']);

        LCNI_DB::ensure_ohlc_latest_snapshot_infrastructure($settings['enabled'], $settings['interval_minutes']);

        return $settings;
    }

    public static function trigger_manual_sync() {
        $started_at = current_time('mysql');
        $status = [
            'running' => true,
            'started_at' => $started_at,
            'ended_at' => '',
            'rows_affected' => 0,
            'message' => 'Đang đồng bộ snapshot OHLC latest...',
            'error' => '',
        ];
        update_option(self::OPTION_STATUS, $status);

        $result = LCNI_DB::refresh_ohlc_latest_snapshot();

        $status['running'] = false;
        $status['ended_at'] = current_time('mysql');
        $status['rows_affected'] = (int) ($result['rows_affected'] ?? 0);
        $status['error'] = (string) ($result['error'] ?? '');
        $status['message'] = empty($status['error']) ? 'Đồng bộ snapshot OHLC latest hoàn tất.' : 'Đồng bộ snapshot OHLC latest thất bại.';
        update_option(self::OPTION_STATUS, $status);

        return $status;
    }
}
