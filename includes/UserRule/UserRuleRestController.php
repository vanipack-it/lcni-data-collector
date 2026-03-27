<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UserRuleRestController
 *
 * REST endpoints:
 *   GET    /lcni/v1/user-rules              — list user's rules
 *   POST   /lcni/v1/user-rules              — create
 *   GET    /lcni/v1/user-rules/{id}         — get detail
 *   PUT    /lcni/v1/user-rules/{id}         — update
 *   DELETE /lcni/v1/user-rules/{id}         — delete
 *   PUT    /lcni/v1/user-rules/{id}/pause   — pause/resume
 *   GET    /lcni/v1/user-rules/{id}/signals — signals list
 *   GET    /lcni/v1/user-rules/{id}/equity  — equity curve
 */
class UserRuleRestController {

    const NS = 'lcni/v1';

    private UserRuleRepository $repo;

    public function __construct( UserRuleRepository $repo ) {
        $this->repo = $repo;
    }

    public function register_routes(): void {
        $ns  = self::NS;
        $auth = [ $this, 'require_permission' ];
        $id_arg = [ 'id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ];

        register_rest_route( $ns, '/user-rules', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'list_rules'   ], 'permission_callback' => $auth ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_rule'  ], 'permission_callback' => $auth,
              'args' => [
                'rule_id'        => [ 'required' => true,  'sanitize_callback' => 'absint' ],
                'is_paper'       => [ 'default'  => true,  'sanitize_callback' => 'rest_sanitize_boolean' ],
                'capital'        => [ 'required' => true,  'sanitize_callback' => static fn($v) => (float)$v ],
                'risk_per_trade' => [ 'default'  => 2.0,   'sanitize_callback' => static fn($v) => (float)$v ],
                'max_symbols'    => [ 'default'  => 5,     'sanitize_callback' => 'absint' ],
                'start_date'     => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                'account_id'     => [ 'default'  => '',    'sanitize_callback' => 'sanitize_text_field' ],
                'auto_order'     => [ 'default'  => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
                'symbol_scope'   => [ 'default'  => 'all', 'sanitize_callback' => 'sanitize_key' ],
                'watchlist_id'   => [ 'default'  => 0,     'sanitize_callback' => 'absint' ],
                'custom_symbols' => [ 'default'  => '',    'sanitize_callback' => 'sanitize_text_field' ],
              ],
            ],
        ] );

        register_rest_route( $ns, '/user-rules/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ $this, 'get_rule'    ], 'permission_callback' => $auth, 'args' => $id_arg ],
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_rule' ], 'permission_callback' => $auth, 'args' => $id_arg ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_rule' ], 'permission_callback' => $auth, 'args' => $id_arg ],
        ] );

        register_rest_route( $ns, '/user-rules/(?P<id>\d+)/pause', [
            'methods' => 'PUT', 'callback' => [ $this, 'toggle_pause' ],
            'permission_callback' => $auth, 'args' => $id_arg,
        ] );

        register_rest_route( $ns, '/user-rules/(?P<id>\d+)/signals', [
            'methods' => 'GET', 'callback' => [ $this, 'list_signals' ],
            'permission_callback' => $auth, 'args' => $id_arg,
        ] );

        register_rest_route( $ns, '/user-rules/(?P<id>\d+)/equity', [
            'methods' => 'GET', 'callback' => [ $this, 'get_equity' ],
            'permission_callback' => $auth, 'args' => $id_arg,
        ] );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function list_rules(): WP_REST_Response {
        $rules = $this->repo->list_user_rules( get_current_user_id() );
        return $this->ok( [ 'rules' => $rules ] );
    }

    public function create_rule( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();

        // Validate: capital > 0
        $capital = (float) $req->get_param('capital');
        if ( $capital <= 0 ) return $this->err( 'Vốn đầu tư phải lớn hơn 0.' );

        // Validate: start_date >= today (no backfill)
        $start = sanitize_text_field( $req->get_param('start_date') );
        if ( strtotime($start) === false ) return $this->err( 'Ngày bắt đầu không hợp lệ.' );
        if ( $start < date('Y-m-d') )      return $this->err( 'Ngày bắt đầu phải từ hôm nay trở đi. Không cho phép áp dụng lùi về quá khứ.' );

        // Check duplicate rule
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lcni_user_rules WHERE user_id=%d AND rule_id=%d",
            $user_id, (int)$req->get_param('rule_id')
        ) );
        if ( $exists ) return $this->err( 'Bạn đã áp dụng rule này rồi. Hãy điều chỉnh cấu hình hiện có.' );

        $id = $this->repo->create_user_rule( [
            'user_id'        => $user_id,
            'rule_id'        => (int)   $req->get_param('rule_id'),
            'is_paper'       => (bool)  $req->get_param('is_paper'),
            'capital'        => $capital,
            'risk_per_trade' => (float) $req->get_param('risk_per_trade'),
            'max_symbols'    => (int)   $req->get_param('max_symbols'),
            'start_date'     => $start,
            'account_id'     => (string)$req->get_param('account_id'),
            'auto_order'     => (bool)  $req->get_param('auto_order'),
            'symbol_scope'   => sanitize_key( (string) $req->get_param('symbol_scope') ) ?: 'all',
            'watchlist_id'   => (int)   $req->get_param('watchlist_id'),
            'custom_symbols' => sanitize_text_field( (string) $req->get_param('custom_symbols') ),
        ] );

        if ( ! $id ) return $this->err( 'Tạo thất bại. Vui lòng thử lại.' );
        return $this->ok( [ 'id' => $id ], 201 );
    }

    public function get_rule( WP_REST_Request $req ): WP_REST_Response {
        $rule = $this->repo->get_user_rule( $req['id'], get_current_user_id() );
        if ( ! $rule ) return $this->err( 'Không tìm thấy.', 404 );
        $rule['performance'] = $this->repo->get_performance( (int)$rule['id'] );
        return $this->ok( [ 'rule' => $rule ] );
    }

    public function update_rule( WP_REST_Request $req ): WP_REST_Response {
        $fields = [ 'capital','risk_per_trade','max_symbols','account_id','auto_order' ];
        $data = [];
        foreach ( $fields as $f ) {
            if ( $req->has_param($f) ) $data[$f] = $req->get_param($f);
        }
        $ok = $this->repo->update_user_rule( $req['id'], get_current_user_id(), $data );
        return $ok ? $this->ok( [] ) : $this->err( 'Cập nhật thất bại.', 404 );
    }

    public function delete_rule( WP_REST_Request $req ): WP_REST_Response {
        $ok = $this->repo->delete_user_rule( $req['id'], get_current_user_id() );
        return $ok ? $this->ok( [] ) : $this->err( 'Không tìm thấy.', 404 );
    }

    public function toggle_pause( WP_REST_Request $req ): WP_REST_Response {
        $rule = $this->repo->get_user_rule( $req['id'], get_current_user_id() );
        if ( ! $rule ) return $this->err( 'Không tìm thấy.', 404 );
        $new_status = $rule['status'] === 'active' ? 'paused' : 'active';
        $this->repo->update_user_rule( $req['id'], get_current_user_id(), [ 'status' => $new_status ] );
        return $this->ok( [ 'status' => $new_status ] );
    }

    public function list_signals( WP_REST_Request $req ): WP_REST_Response {
        $rule = $this->repo->get_user_rule( $req['id'], get_current_user_id() );
        if ( ! $rule ) return $this->err( 'Không tìm thấy.', 404 );
        $status  = sanitize_text_field( $req->get_param('status') ?? '' );
        $signals = $this->repo->list_signals_for_display( $req['id'], $status );
        return $this->ok( [ 'signals' => $signals ] );
    }

    public function get_equity( WP_REST_Request $req ): WP_REST_Response {
        $rule = $this->repo->get_user_rule( $req['id'], get_current_user_id() );
        if ( ! $rule ) return $this->err( 'Không tìm thấy.', 404 );
        $points = $this->repo->get_equity_curve( $req['id'] );
        return $this->ok( [ 'points' => $points, 'count' => count($points) ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function require_login(): bool { return is_user_logged_in(); }

    /**
     * Permission callback đầy đủ: login + gói SaaS có quyền 'user-rule'
     */
    public function require_permission() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        // Admin luôn được phép
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        // Kiểm tra gói SaaS — nếu service chưa load thì cho qua (không chặn)
        global $lcni_saas_service;
        if ( ! $lcni_saas_service ) {
            return true; // SaasService chưa load → không chặn, để tránh false-positive 403
        }
        if ( ! $lcni_saas_service->can( 'user-rule', 'view' ) ) {
            return new WP_Error( 'lcni_forbidden', 'Gói của bạn không có quyền sử dụng Auto Apply Rule.', [ 'status' => 403 ] );
        }
        return true;
    }

    private function ok( array $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( array_merge( ['success' => true], $data ), $status );
    }

    private function err( string $msg, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response( ['success' => false, 'message' => $msg], $status );
    }
}
