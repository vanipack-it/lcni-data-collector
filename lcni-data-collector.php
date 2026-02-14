<?php
/*
Plugin Name: LCNI Data Collector
Description: Lưu dữ liệu OHLC và Security Definition từ DNSE API
Version: 1.2
*/

if (!defined('ABSPATH')) {
    exit;
}

define('LCNI_PATH', plugin_dir_path(__FILE__));
define('LCNI_CRON_HOOK', 'lcni_collect_data_cron');

require_once LCNI_PATH . 'includes/class-lcni-db.php';
require_once LCNI_PATH . 'includes/class-lcni-settings.php';
require_once LCNI_PATH . 'includes/class-lcni-api.php';

function lcni_activate_plugin() {
    LCNI_DB::create_tables();

    if (!wp_next_scheduled(LCNI_CRON_HOOK)) {
        wp_schedule_event(time() + 300, 'hourly', LCNI_CRON_HOOK);
    }
}

function lcni_deactivate_plugin() {
    $timestamp = wp_next_scheduled(LCNI_CRON_HOOK);

    if ($timestamp) {
        wp_unschedule_event($timestamp, LCNI_CRON_HOOK);
    }
}

add_action(LCNI_CRON_HOOK, ['LCNI_DB', 'collect_all_data']);

register_activation_hook(__FILE__, 'lcni_activate_plugin');
register_deactivation_hook(__FILE__, 'lcni_deactivate_plugin');

new LCNI_Settings();
