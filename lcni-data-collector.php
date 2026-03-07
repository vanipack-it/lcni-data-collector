<?php
/*
Plugin Name: LCNI Data Collector
Description: LCNI Market Data Engine: lấy nến, lưu DB, cron auto update
Version: 5.5.6
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
define('LCNI_SEED_FETCH_CRON_HOOK', 'lcni_seed_fetch_cron');
define('LCNI_COMPUTE_CRON_HOOK', 'lcni_compute_cron');
define('LCNI_SNAPSHOT_REFRESH_CRON_HOOK', 'lcni_snapshot_refresh_cron');

require_once LCNI_PATH . 'includes/class-lcni-db.php';
require_once LCNI_PATH . 'includes/class-lcni-api.php';
require_once LCNI_PATH . 'includes/class-lcni-seed-repository.php';
require_once LCNI_PATH . 'includes/class-lcni-history-fetcher.php';
require_once LCNI_PATH . 'includes/class-lcni-seed-scheduler.php';
require_once LCNI_PATH . 'includes/lcni-time-functions.php';
require_once LCNI_PATH . 'includes/class-lcni-settings.php';
require_once LCNI_PATH . 'admin/settings/DataFormatSettings.php';
require_once LCNI_PATH . 'admin/update-data/UpdateDataPage.php';
require_once LCNI_PATH . 'admin/industry/IndustryDataPage.php';
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
require_once LCNI_PATH . 'includes/Repositories/IndustryRepository.php';
require_once LCNI_PATH . 'includes/Services/IndustryAnalysisService.php';
require_once LCNI_PATH . 'includes/API/IndustryController.php';
require_once LCNI_PATH . 'includes/class-lcni-rest-api.php';
require_once LCNI_PATH . 'includes/class-lcni-stock-repository.php';
require_once LCNI_PATH . 'includes/lcni-stock-functions.php';
require_once LCNI_PATH . 'modules/overview/OverviewAjax.php';
require_once LCNI_PATH . 'modules/overview/OverviewShortcode.php';
require_once LCNI_PATH . 'modules/chart/ChartAjax.php';
require_once LCNI_PATH . 'modules/chart/ChartShortcode.php';
require_once LCNI_PATH . 'includes/class-lcni-stock-signals-shortcodes.php';
require_once LCNI_PATH . 'includes/class-lcni-chart-builder-repository.php';
require_once LCNI_PATH . 'includes/class-lcni-chart-builder-service.php';
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
require_once LCNI_PATH . 'modules/chart-builder/ChartBuilderShortcode.php';
require_once LCNI_PATH . 'includes/Member/SaasRepository.php';
require_once LCNI_PATH . 'includes/Member/SaasService.php';
require_once LCNI_PATH . 'includes/Member/MemberSettingsPage.php';
require_once LCNI_PATH . 'includes/Member/MemberAuthShortcodes.php';
require_once LCNI_PATH . 'includes/Member/PermissionMiddleware.php';
require_once LCNI_PATH . 'includes/Member/MemberModule.php';
require_once LCNI_PATH . 'includes/Recommend/RecommendDB.php';
require_once LCNI_PATH . 'includes/Recommend/RuleRepository.php';
require_once LCNI_PATH . 'includes/Recommend/SignalRepository.php';
require_once LCNI_PATH . 'includes/Recommend/PositionEngine.php';
require_once LCNI_PATH . 'includes/Recommend/ExitEngine.php';
require_once LCNI_PATH . 'includes/Recommend/PerformanceCalculator.php';
require_once LCNI_PATH . 'includes/Recommend/DailyCronService.php';
require_once LCNI_PATH . 'includes/Recommend/ShortcodeManager.php';
require_once LCNI_PATH . 'includes/Recommend/Admin/RecommendAdminPage.php';
require_once LCNI_PATH . 'includes/Recommend/RecommendModule.php';
require_once LCNI_PATH . 'includes/class-lcni-industry-shortcodes.php';
require_once LCNI_PATH . 'lcni-industry-monitor/includes/class-industry-data.php';
require_once LCNI_PATH . 'lcni-industry-monitor/includes/class-industry-monitor.php';
require_once LCNI_PATH . 'lcni-industry-monitor/admin/class-industry-settings.php';

if (!defined('LCNI_INDUSTRY_MONITOR_VERSION')) {
    define('LCNI_INDUSTRY_MONITOR_VERSION', '5.5.2');
}

if (!defined('LCNI_INDUSTRY_MONITOR_PATH')) {
    define('LCNI_INDUSTRY_MONITOR_PATH', LCNI_PATH . 'lcni-industry-monitor/');
}

if (!defined('LCNI_INDUSTRY_MONITOR_URL')) {
    define('LCNI_INDUSTRY_MONITOR_URL', LCNI_URL . 'lcni-industry-monitor/');
}

function lcni_register_custom_cron_schedules($schedules) {
    if (!isset($schedules['lcni_every_minute'])) {
        $schedules['lcni_every_minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            // Keep this label non-translated to avoid triggering JIT translation loading too early.
            'display' => 'Every Minute (LCNI)',
        ];
    }

    if (!isset($schedules['five_minutes'])) {
        $schedules['five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => 'Every Five Minutes (LCNI)',
        ];
    }

    return $schedules;
}

function lcni_activate_plugin() {
    LCNI_DB::create_tables();
    LCNI_DB::run_pending_migrations();
    LCNI_Member_Module::activate();
    LCNI_Recommend_Module::activate();
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
    LCNI_Recommend_Module::ensure_infrastructure();

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

    if (!wp_next_scheduled(LCNI_SEED_FETCH_CRON_HOOK)) {
        wp_schedule_event(current_time('timestamp') + 30, 'lcni_every_minute', LCNI_SEED_FETCH_CRON_HOOK);
    }

    if (!wp_next_scheduled(LCNI_COMPUTE_CRON_HOOK)) {
        wp_schedule_event(current_time('timestamp') + (2 * MINUTE_IN_SECONDS), 'five_minutes', LCNI_COMPUTE_CRON_HOOK);
    }

    if (!wp_next_scheduled(LCNI_SNAPSHOT_REFRESH_CRON_HOOK)) {
        wp_schedule_event(current_time('timestamp') + (3 * MINUTE_IN_SECONDS), 'five_minutes', LCNI_SNAPSHOT_REFRESH_CRON_HOOK);
    }

    LCNI_Recommend_Module::ensure_cron();
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

    $seed_fetch_timestamp = wp_next_scheduled(LCNI_SEED_FETCH_CRON_HOOK);
    if ($seed_fetch_timestamp) {
        wp_unschedule_event($seed_fetch_timestamp, LCNI_SEED_FETCH_CRON_HOOK);
    }

    $compute_timestamp = wp_next_scheduled(LCNI_COMPUTE_CRON_HOOK);
    if ($compute_timestamp) {
        wp_unschedule_event($compute_timestamp, LCNI_COMPUTE_CRON_HOOK);
    }

    $snapshot_refresh_timestamp = wp_next_scheduled(LCNI_SNAPSHOT_REFRESH_CRON_HOOK);
    if ($snapshot_refresh_timestamp) {
        wp_unschedule_event($snapshot_refresh_timestamp, LCNI_SNAPSHOT_REFRESH_CRON_HOOK);
    }

    $runtime_update_timestamp = wp_next_scheduled(LCNI_Update_Manager::CRON_HOOK);
    if ($runtime_update_timestamp) {
        wp_unschedule_event($runtime_update_timestamp, LCNI_Update_Manager::CRON_HOOK);
    }

    LCNI_Recommend_Module::deactivate();

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
    // Backward-compatible hook: keep old cron name but use fetch-only stage.
    LCNI_SeedScheduler::run_batch();
}

function lcni_run_seed_fetch_cron() {
    LCNI_SeedScheduler::run_batch();
}

function lcni_run_compute_cron() {
    LCNI_DB::run_compute_pipeline_cron();
}

function lcni_run_snapshot_refresh_cron() {
    LCNI_DB::run_snapshot_refresh_cron();
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
add_action(LCNI_SEED_FETCH_CRON_HOOK, 'lcni_run_seed_fetch_cron');
add_action(LCNI_COMPUTE_CRON_HOOK, 'lcni_run_compute_cron');
add_action(LCNI_SNAPSHOT_REFRESH_CRON_HOOK, 'lcni_run_snapshot_refresh_cron');
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
new LCNI_Chart_Builder_Shortcode();
new LCNI_Update_Data_Page();
new LCNI_Industry_Data_Page();
new LCNI_Member_Module();
new LCNI_Recommend_Module();
new LCNI_Industry_Shortcodes();

$lcni_industry_monitor = new LCNI_Industry_Monitor(new LCNI_Industry_Data());
$lcni_industry_monitor->register_hooks();

if (is_admin()) {
    $lcni_industry_settings = new LCNI_Industry_Settings();
    $lcni_industry_settings->register_hooks();
}

LCNI_Update_Manager::init();
LCNI_OHLC_Latest_Manager::init();
new LCNI_Rest_API();
