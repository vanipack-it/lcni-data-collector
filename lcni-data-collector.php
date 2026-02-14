<?php
/*
Plugin Name: LCNI Data Collector
Description: LCNI Market Data Engine: lấy nến, lưu DB, cron auto update
Version: 1.3
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
    lcni_ensure_cron_scheduled();
}

function lcni_ensure_cron_scheduled() {
    if (wp_next_scheduled(LCNI_CRON_HOOK)) {
        return;
    }

    wp_schedule_event(time() + 300, 'hourly', LCNI_CRON_HOOK);
}

function lcni_deactivate_plugin() {
    $timestamp = wp_next_scheduled(LCNI_CRON_HOOK);

    if ($timestamp) {
        wp_unschedule_event($timestamp, LCNI_CRON_HOOK);
    }
}

function lcni_run_cron_incremental_sync() {
    LCNI_DB::collect_all_data(true);
}

add_action(LCNI_CRON_HOOK, 'lcni_run_cron_incremental_sync');
add_action('init', 'lcni_ensure_cron_scheduled');

register_activation_hook(__FILE__, 'lcni_activate_plugin');
register_deactivation_hook(__FILE__, 'lcni_deactivate_plugin');

new LCNI_Settings();
