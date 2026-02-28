<?php
/*
Plugin Name: LCNI Data Collector
Description: LCNI Market Data Engine: lấy nến, lưu DB, cron auto update
Version: 2.3.4
*/

if (!defined('ABSPATH')) {
    exit;
}

define('LCNI_PATH', plugin_dir_path(__FILE__));
define('LCNI_URL', plugin_dir_url(__FILE__));
define('LCNI_CRON_HOOK', 'lcni_collect_data_cron');
define('LCNI_SEED_CRON_HOOK', 'lcni_seed_batch_cron');
define('LCNI_SECDEF_DAILY_CRON_HOOK', 'lcni_sync_secdef_daily_cron');
define('LCNI_RULE_REBUILD_CRON_HOOK', 'lcni_rule_rebuild_batch_cron');

require_once LCNI_PATH . 'includes/class-lcni-db.php';
require_once LCNI_PATH . 'includes/class-lcni-api.php';
require_once LCNI_PATH . 'includes/class-lcni-seed-repository.php';
require_once LCNI_PATH . 'includes/class-lcni-history-fetcher.php';
require_once LCNI_PATH . 'includes/class-lcni-seed-scheduler.php';
require_once LCNI_PATH . 'includes/lcni-time-functions.php';
require_once LCNI_PATH . 'includes/class-lcni-settings.php';
require_once LCNI_PATH . 'admin/settings/DataFormatSettings.php';
require_once LCNI_PATH . 'admin/update-data/UpdateDataPage.php';
require_once LCNI_PATH . 'includes/Admin/class-lcni-chart-analyst-settings.php';
require_once LCNI_PATH . 'includes/class-lcni-button-registry.php';
require_once LCNI_PATH . 'includes/class-lcni-button-style-config.php';
require_once LCNI_PATH . 'includes/class-lcni-update-manager.php';
require_once LCNI_PATH . 'includes/class-lcni-ohlc-latest-manager.php';
require_once LCNI_PATH . 'includes/Cache/CacheService.php';
require_once LCNI_PATH . 'includes/Permissions/AccessControl.php';
require_once LCNI_PATH . 'includes/Repositories/StockRepository.php';
require_once LCNI_PATH . 'includes/Services/IndicatorService.php';
require_once LCNI_PATH . 'includes/Services/StockQueryService.php';
require_once LCNI_PATH . 'includes/API/StockController.php';
require_once LCNI_PATH . 'includes/class-lcni-rest-api.php';
require_once LCNI_PATH . 'includes/class-lcni-stock-repository.php';
require_once LCNI_PATH . 'includes/lcni-stock-functions.php';
require_once LCNI_PATH . 'modules/overview/OverviewAjax.php';
require_once LCNI_PATH . 'modules/overview/OverviewShortcode.php';
require_once LCNI_PATH . 'modules/chart/ChartAjax.php';
require_once LCNI_PATH . 'modules/chart/ChartShortcode.php';
require_once LCNI_PATH . 'includes/class-lcni-stock-signals-shortcodes.php';
require_once LCNI_PATH . 'includes/class-lcni-stock-detail-router.php';
require_once LCNI_PATH . 'modules/watchlist/WatchlistRepository.php';
require_once LCNI_PATH . 'modules/watchlist/WatchlistService.php';
require_once LCNI_PATH . 'modules/watchlist/WatchlistController.php';
require_once LCNI_PATH . 'modules/watchlist/WatchlistShortcode.php';
require_once LCNI_PATH . 'modules/watchlist/class-lcni-watchlist-module.php';
require_once LCNI_PATH . 'repositories/SnapshotRepository.php';
require_once LCNI_PATH . 'services/CacheService.php';
require_once LCNI_PATH . 'services/FilterService.php';
require_once LCNI_PATH . 'modules/filter/FilterTable.php';
require_once LCNI_PATH . 'modules/filter/FilterAjax.php';
require_once LCNI_PATH . 'modules/filter/FilterAdmin.php';
require_once LCNI_PATH . 'modules/filter/FilterShortcode.php';
require_once LCNI_PATH . 'modules/filter/class-lcni-filter-module.php';

function lcni_register_custom_cron_schedules($schedules) {
    if (!isset($schedules['lcni_every_minute'])) {
        $schedules['lcni_every_minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            // Keep this label non-translated to avoid triggering JIT translation loading too early.
            'display' => 'Every Minute (LCNI)',
        ];
    }

    return $schedules;
}

function lcni_activate_plugin() {
    LCNI_DB::create_tables();
    LCNI_DB::run_pending_migrations();
    lcni_ensure_cron_scheduled();
    (new LCNI_Stock_Detail_Router())->register_rewrite_rule();
    flush_rewrite_rules();
}

function lcni_ensure_plugin_tables() {
    // Avoid expensive schema/index checks on every request (especially wp-admin).
    // Re-check periodically so newly deployed schema changes are still applied.
    if (get_transient('lcni_schema_check_recent') !== false) {
        return;
    }

    LCNI_DB::ensure_tables_exist();

    set_transient('lcni_schema_check_recent', time(), 15 * MINUTE_IN_SECONDS);
}

function lcni_ensure_cron_scheduled() {
    if (!wp_next_scheduled(LCNI_CRON_HOOK)) {
        wp_schedule_event(current_time('timestamp') + 300, 'hourly', LCNI_CRON_HOOK);
    }

    if (!wp_next_scheduled(LCNI_SEED_CRON_HOOK)) {
        wp_schedule_event(current_time('timestamp') + MINUTE_IN_SECONDS, 'lcni_every_minute', LCNI_SEED_CRON_HOOK);
    }

    if (!wp_next_scheduled(LCNI_SECDEF_DAILY_CRON_HOOK)) {
        $timezone = wp_timezone();
        $tomorrow_start = new DateTimeImmutable('tomorrow 08:00:00', $timezone);
        wp_schedule_event($tomorrow_start->getTimestamp(), 'daily', LCNI_SECDEF_DAILY_CRON_HOOK);
    }
}


function lcni_enqueue_stock_detail_assets() {
    if (!is_page_template('page-stock-detail.php')) {
        return;
    }

    $symbol = lcni_get_current_symbol();
    $localized_symbol = wp_json_encode($symbol !== '' ? $symbol : null);

    wp_enqueue_script('lcni-stock-overview');
    wp_enqueue_style('lcni-stock-overview');
    wp_enqueue_script('lcni-chart');
    wp_enqueue_style('lcni-chart-ui');
    wp_enqueue_script('lcni-stock-signals');
    wp_enqueue_style('lcni-stock-signals');

    wp_add_inline_script('lcni-stock-sync', 'window.LCNI_CURRENT_SYMBOL = ' . $localized_symbol . ';', 'before');
}

function lcni_register_frontend_core_assets() {
    $formatter_path = LCNI_PATH . 'assets/js/core/formatter.js';
    $formatter_version = file_exists($formatter_path)
        ? (string) filemtime($formatter_path)
        : '1.0.0';

    wp_register_script(
        'lcni-main-js',
        LCNI_URL . 'assets/js/core/formatter.js',
        [],
        $formatter_version,
        true
    );

    $settings = LCNI_Data_Format_Settings::get_settings();

    wp_localize_script(
        'lcni-main-js',
        'LCNI_FORMAT_CONFIG',
        $settings
    );
}

function lcni_deactivate_plugin() {
    $incremental_timestamp = wp_next_scheduled(LCNI_CRON_HOOK);
    if ($incremental_timestamp) {
        wp_unschedule_event($incremental_timestamp, LCNI_CRON_HOOK);
    }

    $seed_timestamp = wp_next_scheduled(LCNI_SEED_CRON_HOOK);
    if ($seed_timestamp) {
        wp_unschedule_event($seed_timestamp, LCNI_SEED_CRON_HOOK);
    }

    $secdef_timestamp = wp_next_scheduled(LCNI_SECDEF_DAILY_CRON_HOOK);
    if ($secdef_timestamp) {
        wp_unschedule_event($secdef_timestamp, LCNI_SECDEF_DAILY_CRON_HOOK);
    }

    $rule_rebuild_timestamp = wp_next_scheduled(LCNI_RULE_REBUILD_CRON_HOOK);
    if ($rule_rebuild_timestamp) {
        wp_unschedule_event($rule_rebuild_timestamp, LCNI_RULE_REBUILD_CRON_HOOK);
    }

    $runtime_update_timestamp = wp_next_scheduled(LCNI_Update_Manager::CRON_HOOK);
    if ($runtime_update_timestamp) {
        wp_unschedule_event($runtime_update_timestamp, LCNI_Update_Manager::CRON_HOOK);
    }

    flush_rewrite_rules();
}

function lcni_run_cron_incremental_sync() {
    LCNI_DB::run_pending_migrations();

    $stats = LCNI_SeedRepository::get_dashboard_stats();
    if ((int) ($stats['total'] ?? 0) > 0 && (int) ($stats['done'] ?? 0) < (int) ($stats['total'] ?? 0)) {
        return;
    }

    LCNI_DB::collect_all_data(true);
}

function lcni_run_seed_batch() {
    LCNI_SeedScheduler::run_batch();
}

function lcni_run_daily_secdef_sync() {
    LCNI_DB::collect_security_definitions();
}

function lcni_run_rule_rebuild_batch() {
    LCNI_DB::process_rule_rebuild_batch();
}

add_filter('cron_schedules', 'lcni_register_custom_cron_schedules');
add_action(LCNI_CRON_HOOK, 'lcni_run_cron_incremental_sync');
add_action(LCNI_SEED_CRON_HOOK, 'lcni_run_seed_batch');
add_action(LCNI_SECDEF_DAILY_CRON_HOOK, 'lcni_run_daily_secdef_sync');
add_action(LCNI_RULE_REBUILD_CRON_HOOK, 'lcni_run_rule_rebuild_batch');
add_action('plugins_loaded', 'lcni_ensure_plugin_tables');
add_action('init', 'lcni_ensure_cron_scheduled');
add_action('wp_enqueue_scripts', 'lcni_register_frontend_core_assets', 1);
add_action('wp_enqueue_scripts', 'lcni_enqueue_stock_detail_assets', 20);

register_activation_hook(__FILE__, 'lcni_activate_plugin');
register_deactivation_hook(__FILE__, 'lcni_deactivate_plugin');

new LCNI_Settings();
new LCNI_Data_Format_Settings();
new LCNI_Chart_Ajax();
new LCNI_Chart_Shortcode();
new LCNI_Overview_Shortcode();
new LCNI_Stock_Signals_Shortcodes();
new LCNI_Stock_Detail_Router();
new LCNI_Watchlist_Module();
new LCNI_Filter_Module();
new LCNI_Update_Data_Page();
LCNI_Update_Manager::init();
LCNI_OHLC_Latest_Manager::init();
new LCNI_Rest_API();
