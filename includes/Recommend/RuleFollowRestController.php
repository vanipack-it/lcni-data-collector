<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RuleFollowRestController
 *
 * REST endpoints cho tính năng follow rule:
 *
 *   GET  /wp-json/lcni/v1/recommend/rules          — danh sách rules + trạng thái follow
 *   POST /wp-json/lcni/v1/recommend/rules/{id}/follow   — follow rule
 *   POST /wp-json/lcni/v1/recommend/rules/{id}/unfollow — unfollow rule
 *   POST /wp-json/lcni/v1/recommend/rules/{id}/toggle   — toggle follow
 */
class RuleFollowRestController {

    const NS = 'lcni/v1';

    /** @var RuleFollowRepository */
    private $repo;

    public function __construct( RuleFollowRepository $repo ) {
        $this->repo = $repo;
    }

    public function register_routes(): void {
        $ns = self::NS;

        // Danh sách rules + trạng thái follow của user hiện tại
        register_rest_route( $ns, '/recommend/rules', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_rules' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        // Follow
        register_rest_route( $ns, '/recommend/rules/(?P<rule_id>\d+)/follow', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'follow_rule' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args' => [
                'rule_id'              => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'notify_email'         => [ 'default'  => true,  'sanitize_callback' => 'rest_sanitize_boolean' ],
                'notify_browser'       => [ 'default'  => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
                'dynamic_watchlist_id' => [ 'default'  => 0,     'sanitize_callback' => 'absint' ],
                // create_watchlist: JS gửi khi user chọn "Tạo watchlist động mới"
                'create_watchlist'     => [ 'default'  => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
                'watchlist_name'       => [ 'default'  => '',    'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Unfollow
        register_rest_route( $ns, '/recommend/rules/(?P<rule_id>\d+)/unfollow', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'unfollow_rule' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args' => [
                'rule_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // Web Push: lưu subscription từ browser
        register_rest_route( $ns, '/recommend/push/subscribe', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'save_push_subscription' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args' => [
                'endpoint' => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'p256dh'   => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'auth'     => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
            ],
        ] );

        // Web Push: lấy VAPID public key
        register_rest_route( $ns, '/recommend/push/vapid-key', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_vapid_public_key' ],
            'permission_callback' => [ $this, 'require_login' ],
        ] );

        // Open signals cho các rule user đang follow
        register_rest_route( $ns, '/recommend/signals/open', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_open_signals' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args' => [
                'rule_ids' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Toggle (follow ↔ unfollow)
        register_rest_route( $ns, '/recommend/rules/(?P<rule_id>\d+)/toggle', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'toggle_rule' ],
            'permission_callback' => [ $this, 'require_login' ],
            'args' => [
                'rule_id'      => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'notify_email' => [ 'default'  => true, 'sanitize_callback' => 'rest_sanitize_boolean' ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function list_rules( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        $rules   = $this->repo->get_rules_with_follow_status( $user_id );

        // Bổ sung follower_count
        foreach ( $rules as &$rule ) {
            $rule['follower_count'] = $this->repo->count_followers( (int) $rule['id'] );
            $rule['is_following']   = (bool) $rule['is_following'];
            $rule['notify_email']   = (bool) $rule['notify_email'];
        }
        unset( $rule );

        return $this->ok( [ 'rules' => $rules ] );
    }

    public function follow_rule( WP_REST_Request $req ): WP_REST_Response {
        $user_id            = get_current_user_id();
        $rule_id            = (int)  $req->get_param( 'rule_id' );
        $notify_email       = (bool) $req->get_param( 'notify_email' );
        $notify_browser     = (bool) $req->get_param( 'notify_browser' );
        $dynamic_wl_id      = (int)  $req->get_param( 'dynamic_watchlist_id' );
        $create_watchlist   = (bool) $req->get_param( 'create_watchlist' );
        $watchlist_name     = (string) $req->get_param( 'watchlist_name' );

        // Tạo watchlist động mới nếu user yêu cầu
        if ( $create_watchlist && $dynamic_wl_id === 0 ) {
            $wl_name = $watchlist_name ?: 'Signal: ' . $rule_id;
            if ( class_exists( 'LCNI_WatchlistService' ) ) {
                global $lcni_watchlist_service;
                if ( $lcni_watchlist_service instanceof LCNI_WatchlistService ) {
                    $wl_result = $lcni_watchlist_service->create_watchlist( $user_id, $wl_name );
                    if ( ! is_wp_error( $wl_result ) && isset( $wl_result['id'] ) ) {
                        $dynamic_wl_id = (int) $wl_result['id'];
                        // Nạp ngay các symbol đang có signal open của rule
                        $this->sync_open_signals_to_watchlist( $user_id, $rule_id, $dynamic_wl_id );
                    }
                }
            }
        }

        $ok = $this->repo->follow( $user_id, $rule_id, $notify_email, $notify_browser, $dynamic_wl_id );
        if ( ! $ok ) {
            return $this->error_msg( 'Không thể follow rule. Kiểm tra rule_id.' );
        }

        // Gửi email xác nhận follow_rule
        if ( $notify_email && class_exists( 'LCNINotificationManager' ) ) {
            $user      = get_userdata( $user_id );
            $rule_data = $this->get_rule_by_id( $rule_id );
            if ( $user && $rule_data ) {
                LCNINotificationManager::send( 'follow_rule', $user->user_email, [
                    'user_name'  => $user->display_name ?: $user->user_login,
                    'user_email' => $user->user_email,
                    'rule_name'  => (string) ( $rule_data['name'] ?? "Rule #{$rule_id}" ),
                ] );
            }
        }

        // Dispatch inbox notification
        $rule_data_for_inbox = $this->get_rule_by_id( $rule_id );
        do_action( 'lcni_rule_followed', $user_id, [
            'rule_id'   => $rule_id,
            'rule_name' => (string) ( $rule_data_for_inbox['name'] ?? "Rule #{$rule_id}" ),
        ] );

        return $this->ok( [
            'rule_id'              => $rule_id,
            'is_following'         => true,
            'notify_email'         => $notify_email,
            'notify_browser'       => $notify_browser,
            'dynamic_watchlist_id' => $dynamic_wl_id,
            'follower_count'       => $this->repo->count_followers( $rule_id ),
        ] );
    }

    /**
     * Nạp các symbol đang có signal open của rule vào watchlist.
     */
    private function sync_open_signals_to_watchlist( int $user_id, int $rule_id, int $watchlist_id ): void {
        global $wpdb, $lcni_watchlist_service;
        if ( ! ( $lcni_watchlist_service instanceof LCNI_WatchlistService ) ) return;

        $signal_table = $wpdb->prefix . 'lcni_recommend_signal';
        $symbols = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT symbol FROM {$signal_table}
             WHERE rule_id = %d AND status = 'open'",
            $rule_id
        ) );

        foreach ( $symbols as $symbol ) {
            $lcni_watchlist_service->add_symbol( $user_id, $symbol, $watchlist_id );
        }
    }

    public function unfollow_rule( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        $rule_id = (int) $req->get_param( 'rule_id' );

        $this->repo->unfollow( $user_id, $rule_id );

        return $this->ok( [
            'rule_id'              => $rule_id,
            'is_following'         => false,
            'notify_email'         => false,
            'notify_browser'       => false,
            'dynamic_watchlist_id' => 0,
            'follower_count'       => $this->repo->count_followers( $rule_id ),
        ] );
    }

    public function toggle_rule( WP_REST_Request $req ): WP_REST_Response {
        $user_id      = get_current_user_id();
        $rule_id      = (int) $req->get_param( 'rule_id' );
        $notify_email = (bool) $req->get_param( 'notify_email' );

        $is_following = $this->repo->toggle( $user_id, $rule_id );

        // Nếu vừa follow → áp dụng notify_email preference
        if ( $is_following ) {
            $this->repo->follow( $user_id, $rule_id, $notify_email );
        }

        return $this->ok( [
            'rule_id'       => $rule_id,
            'is_following'  => $is_following,
            'notify_email'  => $is_following ? $notify_email : false,
            'follower_count'=> $this->repo->count_followers( $rule_id ),
        ] );
    }

    // ── Web Push ──────────────────────────────────────────────────────────────

    public function get_vapid_public_key( WP_REST_Request $req ): WP_REST_Response {
        $key = (string) get_option('lcni_vapid_public_key', '');
        if ( $key === '' ) {
            return $this->error_msg( 'VAPID keys chưa được khởi tạo. Chạy lại DB migration.', 500 );
        }
        return $this->ok( [ 'publicKey' => $key ] );
    }

    public function save_push_subscription( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $user_id  = get_current_user_id();
        $endpoint = (string) $req->get_param('endpoint');
        $p256dh   = (string) $req->get_param('p256dh');
        $auth     = (string) $req->get_param('auth');

        if ( $endpoint === '' ) {
            return $this->error_msg( 'Endpoint không hợp lệ.' );
        }

        $table = $wpdb->prefix . 'lcni_push_subscriptions';
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND endpoint = %s",
            $user_id, $endpoint
        ) );

        if ( $existing ) {
            $wpdb->update( $table,
                [ 'p256dh' => $p256dh, 'auth' => $auth ],
                [ 'id' => $existing ],
                [ '%s', '%s' ], [ '%d' ]
            );
        } else {
            $wpdb->insert( $table, [
                'user_id'  => $user_id,
                'endpoint' => $endpoint,
                'p256dh'   => $p256dh,
                'auth'     => $auth,
            ], [ '%d', '%s', '%s', '%s' ] );
        }

        return $this->ok( [ 'saved' => true ] );
    }

    // ── Open Signals ──────────────────────────────────────────────────────────

    public function get_open_signals( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $user_id  = get_current_user_id();

        // Parse rule_ids param (comma-separated) hoặc dùng tất cả rule user đang follow
        $raw_ids  = (string) $req->get_param( 'rule_ids' );
        if ( $raw_ids !== '' ) {
            $rule_ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
        } else {
            $rule_ids = $this->repo->get_followed_rule_ids( $user_id );
        }

        if ( empty( $rule_ids ) ) {
            return $this->ok( [ 'signals' => [] ] );
        }

        $signal_table = $wpdb->prefix . 'lcni_recommend_signal';
        $rule_table   = $wpdb->prefix . 'lcni_recommend_rule';

        $placeholders = implode( ',', array_fill( 0, count( $rule_ids ), '%d' ) );

        $sql_args = array_merge(
            [
                "SELECT s.id AS signal_id, s.rule_id, s.symbol,
                        s.entry_price, s.entry_time,
                        s.current_price AS suggested_price,
                        s.r_multiple    AS pnl_pct,
                        s.position_state, s.holding_days,
                        r.name AS rule_name
                 FROM {$signal_table} s
                 LEFT JOIN {$rule_table} r ON r.id = s.rule_id
                 WHERE s.rule_id IN ({$placeholders})
                   AND s.status = 'open'
                 ORDER BY s.entry_time DESC
                 LIMIT 200"
            ],
            array_values( $rule_ids )
        );

        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            call_user_func_array( [ $wpdb, 'prepare' ], $sql_args ),
            ARRAY_A
        ) ?: [];

        return $this->ok( [ 'signals' => $rows ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function require_login(): bool {
        return is_user_logged_in();
    }

    /** Lấy rule data để dùng trong email follow confirmation */
    private function get_rule_by_id( int $rule_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'lcni_recommend_rule';
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, name FROM {$table} WHERE id = %d", $rule_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    private function ok( array $data ): WP_REST_Response {
        return new WP_REST_Response( array_merge( [ 'success' => true ], $data ), 200 );
    }

    private function error_msg( string $msg, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => false, 'message' => $msg ], $status );
    }
}
