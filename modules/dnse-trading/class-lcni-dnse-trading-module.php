<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LCNI_DnseTrading_Module — Giai đoạn 1 + 2
 *
 * CÁCH TÍCH HỢP — Thêm vào lcni-data-collector.php:
 *
 *   // DNSE Trading Module
 *   require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingRepository.php';
 *   require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingApiClient.php';
 *   require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingService.php';
 *   require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingRestController.php';
 *   require_once LCNI_PATH . 'modules/dnse-trading/DnseOrderService.php';
 *   require_once LCNI_PATH . 'modules/dnse-trading/DnseOrderRestController.php';
 *   require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingShortcode.php';
 *   require_once LCNI_PATH . 'modules/dnse-trading/DnseTradingAdminPage.php';
 *   require_once LCNI_PATH . 'modules/dnse-trading/class-lcni-dnse-trading-module.php';
 *   new LCNI_DnseTrading_Module();
 */
class LCNI_DnseTrading_Module {

    /** @var LCNI_DnseTradingService */
    private $service;

    /** @var LCNI_DnseOrderService */
    private $order_service;

    /** @var LCNI_Dnse_Gmail_OAuth_Service */
    private $gmail;

    /** @var LCNI_DnseTradingRepository */
    private $repo;

    public function __construct() {
        LCNI_DnseTradingRepository::maybe_create_tables();

        $repo                = new LCNI_DnseTradingRepository();
        $api                 = new LCNI_DnseTradingApiClient();
        $this->repo          = $repo;
        $this->service       = new LCNI_DnseTradingService( $repo, $api );
        $this->order_service = new LCNI_DnseOrderService( $repo, $api );
        $this->gmail         = new LCNI_Dnse_Gmail_OAuth_Service( $repo, $api );

        new LCNI_DnseTradingShortcode( $this->service, $this->order_service );
        // Admin page: render qua lcni-settings&tab=dnse_trading (không cần khởi tạo)

        // REST routes — đăng ký cả Phase 1 + Phase 2
        add_action( 'rest_api_init', [ $this, 'register_all_routes' ] );

        // Cron: auto-sync mỗi 15 phút
        add_action( 'lcni_dnse_auto_sync_cron', [ $this, 'run_auto_sync' ] );
        add_action( 'init', [ $this, 'ensure_cron' ] );
    }

    public function register_all_routes(): void {
        $repo  = new LCNI_DnseTradingRepository();
        $api   = new LCNI_DnseTradingApiClient();
        $svc   = new LCNI_DnseTradingService( $repo, $api );
        $ord   = new LCNI_DnseOrderService( $repo, $api );
        $gmail = new LCNI_Dnse_Gmail_OAuth_Service( $repo, $api );

        ( new LCNI_DnseTradingRestController( $svc, $gmail, $repo ) )->register_routes();
        ( new LCNI_DnseOrderRestController( $ord, $svc ) )->register_routes();
    }

    public static function activate(): void {
        LCNI_DnseTradingRepository::create_tables();
    }

    public function ensure_cron(): void {
        if ( ! wp_next_scheduled( 'lcni_dnse_auto_sync_cron' ) ) {
            // Dùng lcni_every_minute đã đăng ký sẵn trong plugin chính
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'lcni_every_minute', 'lcni_dnse_auto_sync_cron' );
        }
    }

    public function run_auto_sync(): void {
        global $wpdb;
        $tbl  = $wpdb->prefix . 'lcni_dnse_credentials';
        $now  = time();

        // ── Gmail Auto-Renew trading token ────────────────────────────────
        // Chỉ renew nếu token sắp hết hạn (< 30 phút) — không chạy đồng thời
        // cho nhiều user để tránh Gmail rate limit
        $renew_users = $this->repo->get_users_with_valid_jwt();
        foreach ( $renew_users as $uid ) {
            // Chỉ renew nếu user đã cấp quyền auto renew
            if ( ! $this->repo->has_permission( (int) $uid, 'perm_auto_renew' ) ) {
                continue;
            }
            $result = $this->gmail->auto_renew_trading_token( (int) $uid );
            if ( is_wp_error( $result ) ) {
                error_log( '[LCNI DNSE] Gmail auto-renew error: ' . $result->get_error_message() );
            }
        }

        // ── Sync data bình thường ─────────────────────────────────────────
        // Lấy users có JWT hợp lệ — bao gồm cả user vừa được re-login ở bước trên
        $prev = $wpdb->suppress_errors( true );
        $users = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$tbl}
                 WHERE jwt_expires_at > %d
                 ORDER BY last_sync_at ASC
                 LIMIT 50",
                time()
            )
        ) ?: [];
        $wpdb->suppress_errors( $prev );

        foreach ( $users as $user_id ) {
            $this->service->sync_all( (int) $user_id );
            usleep( 300_000 );
        }
    }
}
