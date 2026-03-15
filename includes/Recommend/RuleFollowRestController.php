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
                'rule_id'      => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'notify_email' => [ 'default'  => true, 'sanitize_callback' => 'rest_sanitize_boolean' ],
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
        $user_id      = get_current_user_id();
        $rule_id      = (int) $req->get_param( 'rule_id' );
        $notify_email = (bool) $req->get_param( 'notify_email' );

        $ok = $this->repo->follow( $user_id, $rule_id, $notify_email );
        if ( ! $ok ) {
            return $this->error_msg( 'Không thể follow rule. Kiểm tra rule_id.' );
        }

        return $this->ok( [
            'rule_id'       => $rule_id,
            'is_following'  => true,
            'notify_email'  => $notify_email,
            'follower_count'=> $this->repo->count_followers( $rule_id ),
        ] );
    }

    public function unfollow_rule( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        $rule_id = (int) $req->get_param( 'rule_id' );

        $this->repo->unfollow( $user_id, $rule_id );

        return $this->ok( [
            'rule_id'       => $rule_id,
            'is_following'  => false,
            'follower_count'=> $this->repo->count_followers( $rule_id ),
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function require_login(): bool {
        return is_user_logged_in();
    }

    private function ok( array $data ): WP_REST_Response {
        return new WP_REST_Response( array_merge( [ 'success' => true ], $data ), 200 );
    }

    private function error_msg( string $msg, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => false, 'message' => $msg ], $status );
    }
}
