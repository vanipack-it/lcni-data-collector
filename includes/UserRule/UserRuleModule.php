<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UserRuleModule — bootstrap tất cả classes liên quan đến User Rule / Paper Trading.
 *
 * Đăng ký:
 *   - DB tables (lazy install)
 *   - Repository, Engine, REST controller, Shortcode
 *   - Hooks vào SignalRepository + DailyCronService
 */
class UserRuleModule {

    private static ?UserRuleRepository $repo = null;

    public static function get_repo(): UserRuleRepository {
        if ( self::$repo === null ) {
            global $wpdb;
            self::$repo = new UserRuleRepository( $wpdb );
        }
        return self::$repo;
    }

    public function __construct() {
        global $wpdb;

        // Lazy install DB
        UserRuleDB::ensure( $wpdb );

        // Bootstrap
        $repo   = self::get_repo();
        $engine = new UserRuleEngine( $repo, $this->get_dnse_client() );
        $engine->register_hooks();

        // REST routes phải đăng ký trong rest_api_init
        add_action( 'rest_api_init', static function() use ( $repo ) {
            ( new UserRuleRestController( $repo ) )->register_routes();
        } );

        // Inject SaasService vào Shortcode để check permission trực tiếp
        global $lcni_saas_service;
        new UserRuleShortcode( $repo, $lcni_saas_service ?? null );

        // user-rule module is registered statically in SaasService::$modules
    }

    public static function activate(): void {
        global $wpdb;
        UserRuleDB::install( $wpdb );
    }

    /** Lấy DNSE API client nếu module đang active */
    private function get_dnse_client(): ?LCNI_DnseTradingApiClient {
        if ( class_exists( 'LCNI_DnseTradingApiClient' ) ) {
            return new LCNI_DnseTradingApiClient();
        }
        return null;
    }
}
