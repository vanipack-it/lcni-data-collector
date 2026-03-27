<?php
/*
Plugin Name: LCNI Data Collector
Description: LCNI Market Data Engine: lấy nến, lưu DB, cron auto update
Version: 26.3.23.0
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

// Redis Cache Layer — phải load trước class-lcni-db.php
require_once LCNI_PATH . 'includes/Cache/LCNI_RedisCache.php';
require_once LCNI_PATH . 'includes/class-lcni-db.php';
require_once LCNI_PATH . 'includes/class-lcni-table-config.php';
require_once LCNI_PATH . 'includes/class-lcni-compute-control.php';
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

// Đăng ký Redis cache groups — cho phép wp_cache_flush_group() hoạt động
add_action('init', function() {
    if (function_exists('wp_cache_add_global_groups')) {
        wp_cache_add_global_groups([
            LCNI_RedisCache::GRP_REF,
            LCNI_RedisCache::GRP_OHLC_LATEST,
            LCNI_RedisCache::GRP_MARKET_STATS,
            LCNI_RedisCache::GRP_REST,
            LCNI_RedisCache::GRP_STATIC,
        ]);
    }
}, 1);
require_once LCNI_PATH . 'includes/Cache/LCNI_CacheFlushController.php';
$lcni_cache_flush_ctrl = new LCNI_CacheFlushController();
$lcni_cache_flush_ctrl->register_hooks();
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
// ── Notification System (load trước Member để welcome email hook sẵn sàng) ─
require_once LCNI_PATH . 'includes/Notification/LCNINotificationManager.php';
require_once LCNI_PATH . 'includes/Notification/LCNINotificationAdminPage.php';
require_once LCNI_PATH . 'includes/Notification/LCNINotificationPreviewAjax.php';
// Inbox (in-app notification)
require_once LCNI_PATH . 'includes/Notification/LCNI_InboxDB.php';
require_once LCNI_PATH . 'includes/Notification/LCNI_InboxRestAPI.php';
require_once LCNI_PATH . 'includes/Notification/LCNI_InboxDispatcher.php';
require_once LCNI_PATH . 'includes/Notification/LCNI_InboxModule.php';

require_once LCNI_PATH . 'includes/Member/SaasRepository.php';
require_once LCNI_PATH . 'includes/Member/SaasService.php';
require_once LCNI_PATH . 'includes/Member/MemberSettingsPage.php';
require_once LCNI_PATH . 'includes/Member/MemberAuthShortcodes.php';
require_once LCNI_PATH . 'includes/Member/MemberProfileShortcode.php';
require_once LCNI_PATH . 'includes/Member/MemberPackageShortcode.php';
require_once LCNI_PATH . 'includes/Member/PermissionMiddleware.php';
require_once LCNI_PATH . 'includes/Member/MemberAdminUserFields.php';
require_once LCNI_PATH . 'includes/Member/MemberPricingShortcode.php';
require_once LCNI_PATH . 'includes/Member/GoogleOAuthHandler.php';
require_once LCNI_PATH . 'includes/Member/DnseLoginHandler.php';
require_once LCNI_PATH . 'includes/Member/UpgradeRequestRepository.php';
require_once LCNI_PATH . 'includes/Member/UpgradeRequestService.php';
require_once LCNI_PATH . 'includes/Member/UpgradeRequestShortcode.php';
require_once LCNI_PATH . 'includes/Member/UpgradeRequestAdminPage.php';
require_once LCNI_PATH . 'includes/Member/MemberAvatarHelper.php';
require_once LCNI_PATH . 'includes/Member/MemberModule.php';

// ── Marketing Module ──────────────────────────────────────────────────────────
require_once LCNI_PATH . 'includes/Marketing/MarketingDB.php';
require_once LCNI_PATH . 'includes/Marketing/MarketingRepository.php';
require_once LCNI_PATH . 'includes/Marketing/MarketingService.php';
require_once LCNI_PATH . 'includes/Marketing/MarketingRestController.php';
require_once LCNI_PATH . 'includes/Marketing/MarketingAdminPage.php';
require_once LCNI_PATH . 'includes/Marketing/MarketingShortcode.php';
require_once LCNI_PATH . 'includes/Marketing/MarketingModule.php';
require_once LCNI_PATH . 'modules/portfolio/PortfolioRepository.php';
require_once LCNI_PATH . 'modules/portfolio/PortfolioService.php';
require_once LCNI_PATH . 'modules/portfolio/PortfolioController.php';
require_once LCNI_PATH . 'modules/portfolio/PortfolioShortcode.php';
require_once LCNI_PATH . 'modules/portfolio/PortfolioAdminPage.php';
require_once LCNI_PATH . 'modules/portfolio/class-lcni-portfolio-module.php';
require_once LCNI_PATH . 'modules/heatmap/HeatmapAjax.php';
require_once LCNI_PATH . 'modules/heatmap/HeatmapShortcode.php';
require_once LCNI_PATH . 'modules/heatmap/HeatmapAdmin.php';
require_once LCNI_PATH . 'modules/heatmap/class-lcni-heatmap-module.php';
require_once LCNI_PATH . 'modules/theme-integration/ThemeIntegrationModule.php';
require_once LCNI_PATH . 'includes/Recommend/RecommendDB.php';
require_once LCNI_PATH . 'includes/Recommend/RuleRepository.php';
require_once LCNI_PATH . 'includes/Recommend/SignalRepository.php';
require_once LCNI_PATH . 'includes/Recommend/PositionEngine.php';
require_once LCNI_PATH . 'includes/Recommend/ExitEngine.php';
require_once LCNI_PATH . 'includes/Recommend/PerformanceCalculator.php';
require_once LCNI_PATH . 'includes/Recommend/DailyCronService.php';
require_once LCNI_PATH . 'includes/Recommend/ShortcodeManager.php';
require_once LCNI_PATH . 'includes/Recommend/Admin/RecommendAdminPage.php';
require_once LCNI_PATH . 'includes/Recommend/RuleFollowRepository.php';
require_once LCNI_PATH . 'includes/Recommend/RuleFollowNotifier.php';
add_action( 'lcni_send_queued_notification',        [ 'RuleFollowNotifier', 'handle_queued_notification' ] );
add_action( 'lcni_send_queued_digest_notification', [ 'RuleFollowNotifier', 'handle_queued_digest_notification' ], 10, 5 );
require_once LCNI_PATH . 'includes/Recommend/RuleFollowRestController.php';
require_once LCNI_PATH . 'includes/Recommend/RuleFollowShortcode.php';
require_once LCNI_PATH . 'includes/Recommend/PushServiceWorkerEndpoint.php';
require_once LCNI_PATH . 'includes/Recommend/RecommendModule.php';

// ── User Rule / Paper Trading Module ──────────────────────────────────────
require_once LCNI_PATH . 'includes/UserRule/UserRuleDB.php';
require_once LCNI_PATH . 'includes/UserRule/UserRuleRepository.php';
require_once LCNI_PATH . 'includes/UserRule/UserRuleEngine.php';
require_once LCNI_PATH . 'includes/UserRule/UserRuleRestController.php';
require_once LCNI_PATH . 'includes/UserRule/UserRuleShortcode.php';
require_once LCNI_PATH . 'includes/UserRule/UserRuleNotifier.php';
require_once LCNI_PATH . 'includes/UserRule/UserRuleNotificationAdminPage.php';
require_once LCNI_PATH . 'includes/UserRule/UserRuleModule.php';
require_once LCNI_PATH . 'includes/UserStats/UserStatsRepository.php';
require_once LCNI_PATH . 'includes/UserStats/UserStatsAdminPage.php';
// DNSE Trading Module — Giai đoạn 1 + 2
// Wrapped in try/catch: a fatal in any DNSE class must NOT kill the entire WP page
try {
    require_once LCNI_PATH . 'modules/dnse-trading/DnseGmailOAuthService.php';
    require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingRepository.php';
    require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingApiClient.php';
    require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingService.php';
    require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingRestController.php';
    require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingShortcode.php';
    require_once LCNI_PATH . 'modules/dnse-trading/DnseOrderService.php';
    require_once LCNI_PATH . 'modules/dnse-trading/DnseOrderRestController.php';
    require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingAdminPage.php';
    require_once LCNI_PATH . 'modules/dnse-trading/class-lcni-dnse-trading-module.php';
    new LCNI_DnseTrading_Module();
} catch ( \Throwable $e ) {
    error_log( '[LCNI] DNSE Trading Module failed to load: ' . $e->getMessage() );
}
require_once LCNI_PATH . 'includes/MarketDashboard/MarketDashboardRepository.php';
require_once LCNI_PATH . 'includes/MarketDashboard/MarketDashboardRestController.php';
require_once LCNI_PATH . 'includes/MarketDashboard/MarketDashboardShortcode.php';
require_once LCNI_PATH . 'includes/MarketDashboard/MarketChartShortcode.php';
// Gutenberg blocks — chỉ load trong admin (editor) để tránh overhead frontend
if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
    require_once LCNI_PATH . 'blocks/blocks.php';
}
new LCNI_MarketDashboardShortcode();
new LCNI_MarketChartShortcode();
require_once LCNI_PATH . 'includes/class-lcni-industry-shortcodes.php';
require_once LCNI_PATH . 'lcni-industry-monitor/includes/class-industry-data.php';
require_once LCNI_PATH . 'lcni-industry-monitor/includes/class-industry-monitor.php';
require_once LCNI_PATH . 'lcni-industry-monitor/admin/class-industry-settings.php';
require_once LCNI_PATH . 'lcni-industry-monitor/includes/class-im-monitor-db.php';
require_once LCNI_PATH . 'lcni-industry-monitor/includes/class-im-symbol-data.php';
require_once LCNI_PATH . 'lcni-industry-monitor/includes/class-im-shortcode.php';
require_once LCNI_PATH . 'lcni-industry-monitor/includes/class-im-admin.php';
require_once LCNI_PATH . 'includes/CustomIndex/lcni-custom-index-loader.php';
require_once LCNI_PATH . 'includes/Screenshot/ScreenshotModule.php';

if (!defined('LCNI_INDUSTRY_MONITOR_VERSION')) {
    define('LCNI_INDUSTRY_MONITOR_VERSION', '6.0.1');
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

    return $schedules;
}

function lcni_activate_plugin() {
    LCNI_DB::create_tables();
    LCNI_DB::run_pending_migrations();
    LCNI_Member_Module::activate();
    LCNI_Marketing_Module::activate();
    LCNI_Portfolio_Module::activate();
    LCNI_Recommend_Module::activate();
    UserRuleModule::activate();
    lcni_ensure_cron_scheduled();
    (new LCNI_Stock_Detail_Router())->register_rewrite_rule();
    flush_rewrite_rules();
    // Tự động tạo API key cho cache flush nếu chưa có
    lcni_maybe_generate_sync_api_key();
}

/**
 * Tạo lcni_sync_api_key trong wp_options nếu chưa tồn tại.
 * Gọi khi activate plugin và khi admin vào trang Settings.
 */
function lcni_maybe_generate_sync_api_key(): void {
    if ( get_option( 'lcni_sync_api_key', '' ) !== '' ) {
        return; // Đã có key, không ghi đè
    }
    // Tạo key 32 bytes = 64 hex chars, đủ mạnh cho HMAC-SHA256
    $key = bin2hex( random_bytes( 32 ) );
    add_option( 'lcni_sync_api_key', $key, '', 'no' ); // autoload=no
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
    if ( LCNI_Compute_Control::is_enabled('lcni_compute_incremental_sync') ) {
        if (!wp_next_scheduled(LCNI_CRON_HOOK)) {
            wp_schedule_event(current_time('timestamp') + 300, 'hourly', LCNI_CRON_HOOK);
        }
    }

    if ( LCNI_Compute_Control::is_enabled('lcni_compute_seed_batch') ) {
        if (!wp_next_scheduled(LCNI_SEED_CRON_HOOK)) {
            wp_schedule_event(current_time('timestamp') + MINUTE_IN_SECONDS, 'lcni_every_minute', LCNI_SEED_CRON_HOOK);
        }
    }

    // Disable background secdef sync to avoid repeated cached-sync failures.
    wp_clear_scheduled_hook(LCNI_SECDEF_DAILY_CRON_HOOK);

    if ( LCNI_Compute_Control::is_enabled('lcni_compute_recommend_cron') ) {
        LCNI_Recommend_Module::ensure_cron();
    }

    // Market Context Sync: chạy sau mỗi phiên để cập nhật snapshot thị trường
    if ( LCNI_Compute_Control::is_enabled('lcni_compute_market_context') ) {
        if ( ! wp_next_scheduled( 'lcni_market_context_sync_cron' ) ) {
            wp_schedule_event( current_time('timestamp') + MINUTE_IN_SECONDS * 5, 'hourly', 'lcni_market_context_sync_cron' );
        }
    }

    // Market Context Backfill: chạy 1 lần, tắt sau khi done
    if ( LCNI_Compute_Control::is_enabled('lcni_compute_market_backfill') ) {
        if ( ! wp_next_scheduled( 'lcni_market_context_backfill_cron' ) ) {
            wp_schedule_event( current_time('timestamp') + MINUTE_IN_SECONDS, 'lcni_every_minute', 'lcni_market_context_backfill_cron' );
        }
    }
}


function lcni_enqueue_stock_detail_assets() {
    // Enqueue assets đầy đủ chỉ khi dùng page template riêng
    if (is_page_template('page-stock-detail.php')) {
        wp_enqueue_script('lcni-stock-overview');
        wp_enqueue_style('lcni-stock-overview');
        wp_enqueue_script('lcni-chart');
        wp_enqueue_style('lcni-chart-ui');
        wp_enqueue_script('lcni-stock-signals');
        wp_enqueue_style('lcni-stock-signals');
    }

    // Set LCNI_CURRENT_SYMBOL cho mọi trang có lcni-stock-sync đã được enqueue
    // (shortcode _query cần JS đọc được symbol từ URL query param qua window.LCNI_CURRENT_SYMBOL)
    add_action('wp_footer', function () {
        if (!wp_script_is('lcni-stock-sync', 'enqueued') && !wp_script_is('lcni-stock-sync', 'done')) {
            return;
        }
        $symbol = lcni_get_current_symbol();
        $localized_symbol = wp_json_encode($symbol !== '' ? $symbol : null);
        echo '<script>if(typeof window.LCNI_CURRENT_SYMBOL==="undefined"||!window.LCNI_CURRENT_SYMBOL){window.LCNI_CURRENT_SYMBOL=' . $localized_symbol . ';}</script>';
    }, 5);
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

    // ── Table Engine Core ─────────────────────────────────────
    // Sticky engine dùng chung cho mọi module (filter, watchlist, recommend...)
    $engine_path = LCNI_PATH . 'assets/js/lcni-table-engine.js';
    $engine_ver  = file_exists( $engine_path ) ? (string) filemtime( $engine_path ) : '1.0.0';
    wp_register_script(
        'lcni-table-engine',
        LCNI_URL . 'assets/js/lcni-table-engine.js',
        [ 'lcni-main-js' ],
        $engine_ver,
        true
    );
    wp_enqueue_script( 'lcni-table-engine' );

    $settings = LCNI_Data_Format_Settings::get_settings();

    wp_localize_script(
        'lcni-main-js',
        'LCNI_FORMAT_CONFIG',
        $settings
    );

    // ── Global Table UI System ────────────────────────────────
    // Enqueue sớm (priority 1) để mọi module CSS override đúng thứ tự.
    $ui_table_path = LCNI_PATH . 'assets/css/lcni-ui-table.css';
    $ui_table_ver  = file_exists($ui_table_path) ? (string) filemtime($ui_table_path) : '1.0.0';
    wp_enqueue_style(
        'lcni-ui-table',
        LCNI_URL . 'assets/css/lcni-ui-table.css',
        [],
        $ui_table_ver
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

    LCNI_Recommend_Module::deactivate();

    flush_rewrite_rules();
}

function lcni_run_cron_incremental_sync() {
    LCNI_DB::run_pending_migrations();

    $stats = LCNI_SeedRepository::get_dashboard_stats();
    if ((int) ($stats['total'] ?? 0) > 0 && (int) ($stats['done'] ?? 0) < (int) ($stats['total'] ?? 0)) {
        return;
    }

    LCNI_DB::collect_ohlc_data(true);
}

function lcni_run_seed_batch() {
    LCNI_SeedScheduler::run_batch();
    LCNI_DB::run_seed_serial_pipeline('seed_cron');
}

function lcni_run_daily_secdef_sync() {
    LCNI_DB::collect_security_definitions();
}

function lcni_run_rule_rebuild_batch() {
    LCNI_DB::process_rule_rebuild_batch();
}

// ── Compute Control – bật/tắt cron từ admin UI ──────────────────────────────
LCNI_Compute_Control::init();

add_filter('cron_schedules', 'lcni_register_custom_cron_schedules');

if ( LCNI_Compute_Control::is_enabled('lcni_compute_incremental_sync') ) {
    add_action( LCNI_CRON_HOOK, 'lcni_run_cron_incremental_sync' );
}
if ( LCNI_Compute_Control::is_enabled('lcni_compute_seed_batch') ) {
    add_action( LCNI_SEED_CRON_HOOK, 'lcni_run_seed_batch' );
}
if ( LCNI_Compute_Control::is_enabled('lcni_compute_rule_rebuild') ) {
    add_action( LCNI_RULE_REBUILD_CRON_HOOK, 'lcni_run_rule_rebuild_batch' );
}

add_action('plugins_loaded', 'lcni_ensure_plugin_tables');
add_action('init', 'lcni_ensure_cron_scheduled');
// Inject unified table CSS vars into <head> — priority 5, before module CSS
LCNI_Table_Config::register_wp_head();

// Market Context cron handlers
add_action( 'lcni_market_context_sync_cron', function () {
    if ( ! LCNI_Compute_Control::is_enabled( 'lcni_compute_market_context' ) ) {
        return;
    }
    if ( ! class_exists( 'LCNI_MarketDashboardRepository' ) ) {
        return;
    }
    $repo = new LCNI_MarketDashboardRepository();
    foreach ( [ '1D', '1W', '1M' ] as $tf ) {
        $repo->get_snapshot( $tf, 0, false ); // force recalculate latest
    }
} );

add_action( 'lcni_market_context_backfill_cron', function () {
    if ( ! LCNI_Compute_Control::is_enabled( 'lcni_compute_market_backfill' ) ) {
        wp_clear_scheduled_hook( 'lcni_market_context_backfill_cron' );
        return;
    }
    if ( ! class_exists( 'LCNI_MarketDashboardRepository' ) ) {
        return;
    }
    $repo  = new LCNI_MarketDashboardRepository();
    $saved = 0;
    foreach ( [ '1D', '1W', '1M' ] as $tf ) {
        $saved += $repo->backfill_history( $tf, 200 );
    }
    // Tắt cron sau khi backfill xong (không còn phiên thiếu)
    if ( $saved === 0 ) {
        LCNI_Compute_Control::save_settings(
            array_merge( LCNI_Compute_Control::get_settings(), [ 'lcni_compute_market_backfill' => false ] )
        );
        wp_clear_scheduled_hook( 'lcni_market_context_backfill_cron' );
    }
} );
add_action('wp_enqueue_scripts', 'lcni_register_frontend_core_assets', 1);
// Đảm bảo API key tồn tại kể cả khi plugin được deploy thủ công (không qua activate)
add_action('admin_init', 'lcni_maybe_generate_sync_api_key', 5);
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
new LCNI_Heatmap_Module();
new LCNI_Chart_Builder_Shortcode();
new LCNI_Update_Data_Page();
new LCNI_Industry_Data_Page();
new LCNI_Member_Module();
new LCNI_Marketing_Module();
new LCNINotificationAdminPage();
new UserRuleNotificationAdminPage();
new LCNINotificationPreviewAjax();
// Inbox notification
$lcni_inbox_module     = new LCNI_InboxModule();
$lcni_inbox_module->register_hooks();
$lcni_inbox_rest       = new LCNI_InboxRestAPI();
$lcni_inbox_rest->register_hooks();
$lcni_inbox_dispatcher = new LCNI_InboxDispatcher();
$lcni_inbox_dispatcher->register_hooks();
new LCNI_Portfolio_Module();
new LCNI_Recommend_Module();
new UserRuleModule();
if ( is_admin() ) {
    global $wpdb;
    new LCNI_UserStatsAdminPage( new LCNI_UserStatsRepository( $wpdb ) );
}
new LCNI_Industry_Shortcodes();

// Theme integration — chỉ kích hoạt khi Stock Dashboard Theme đang active
$lcni_active_theme = get_stylesheet();
if ( $lcni_active_theme === 'stock-dashboard-theme' || get_template() === 'stock-dashboard-theme' ) {
    // Lấy service từ MemberModule đã khởi tạo ở trên
    // ThemeIntegrationModule cần SaasService — khởi tạo riêng (repo đã có migration xong)
    $lcni_theme_repo    = new LCNI_SaaS_Repository();
    $lcni_theme_service = new LCNI_SaaS_Service( $lcni_theme_repo );
    new LCNI_Theme_Integration_Module( $lcni_theme_service );
}

// Industry Monitor v2 — đa shortcode
$lcni_im_shortcode = new LCNI_IM_Shortcode();
$lcni_im_shortcode->register_hooks();

if (is_admin()) {
    $lcni_industry_settings = new LCNI_Industry_Settings();
    $lcni_industry_settings->register_hooks();
    $lcni_im_admin = new LCNI_IM_Admin();
    $lcni_im_admin->register_hooks();
}

LCNI_Update_Manager::init();
LCNI_OHLC_Latest_Manager::init();
new LCNI_Rest_API();
