<?php
/**
 * Custom Index REST Controller
 *
 * Endpoints:
 *   GET  /lcni/v1/custom-indexes               — danh sách chỉ số
 *   POST /lcni/v1/custom-indexes               — tạo mới (admin)
 *   GET  /lcni/v1/custom-indexes/{id}          — chi tiết
 *   PUT  /lcni/v1/custom-indexes/{id}          — cập nhật (admin)
 *   DEL  /lcni/v1/custom-indexes/{id}          — xóa (admin)
 *   GET  /lcni/v1/custom-indexes/{id}/candles  — OHLC data
 *   POST /lcni/v1/custom-indexes/{id}/backfill — trigger backfill (admin)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_Custom_Index_Rest_Controller {

    private LCNI_Custom_Index_Repository $repo;
    private LCNI_Custom_Index_Calculator $calc;

    public function __construct(
        LCNI_Custom_Index_Repository $repo,
        LCNI_Custom_Index_Calculator $calc
    ) {
        $this->repo = $repo;
        $this->calc = $calc;
    }

    public function register_routes(): void {
        $ns   = 'lcni/v1';
        $base = '/custom-indexes';

        register_rest_route( $ns, $base, [
            [ 'methods' => 'GET',  'callback' => [ $this, 'list_indexes' ],
              'permission_callback' => '__return_true' ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_index' ],
              'permission_callback' => [ $this, 'require_admin' ],
              'args' => $this->create_args() ],
        ] );

        register_rest_route( $ns, $base . '/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ $this, 'get_index' ],
              'permission_callback' => '__return_true' ],
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_index' ],
              'permission_callback' => [ $this, 'require_admin' ],
              'args' => $this->create_args( false ) ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_index' ],
              'permission_callback' => [ $this, 'require_admin' ] ],
        ] );

        register_rest_route( $ns, $base . '/(?P<id>\d+)/candles', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_candles' ],
            'permission_callback' => '__return_true',
            'args' => [
                'timeframe' => [ 'default' => '1D', 'sanitize_callback' => 'sanitize_text_field' ],
                'limit'     => [ 'default' => 200,  'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( $ns, $base . '/(?P<id>\d+)/backfill', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'backfill' ],
            'permission_callback' => [ $this, 'require_admin' ],
            'args' => [
                'timeframe' => [ 'default' => '1D', 'sanitize_callback' => 'sanitize_text_field' ],
                'limit'     => [ 'default' => 0,    'sanitize_callback' => 'absint' ],
                'reset'     => [ 'default' => false,'sanitize_callback' => 'rest_sanitize_boolean' ],
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function list_indexes(): WP_REST_Response {
        $indexes = $this->repo->all();
        foreach ( $indexes as &$idx ) {
            $idx['ohlc_count'] = $this->repo->get_ohlc_count( (int) $idx['id'] );
            $latest = $this->calc->get_latest_candle( (int) $idx['id'], '1D' );
            $idx['latest_close'] = $latest ? (float) $latest['close_value'] : null;
        }
        return new WP_REST_Response( [ 'indexes' => $indexes ], 200 );
    }

    public function get_index( WP_REST_Request $req ): WP_REST_Response {
        $index = $this->repo->find( (int) $req['id'] );
        if ( ! $index ) return $this->err( 'Không tìm thấy chỉ số.', 404 );
        $index['ohlc_count'] = $this->repo->get_ohlc_count( (int) $index['id'] );
        return new WP_REST_Response( $index, 200 );
    }

    public function create_index( WP_REST_Request $req ): WP_REST_Response {
        $id = $this->repo->create( $req->get_params() );
        if ( ! $id ) return $this->err( 'Tạo thất bại.' );
        return new WP_REST_Response( [ 'id' => $id ], 201 );
    }

    public function update_index( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req['id'];
        if ( ! $this->repo->find( $id ) ) return $this->err( 'Không tìm thấy.', 404 );
        $ok = $this->repo->update( $id, $req->get_params() );
        return new WP_REST_Response( [ 'updated' => $ok ], $ok ? 200 : 500 );
    }

    public function delete_index( WP_REST_Request $req ): WP_REST_Response {
        $ok = $this->repo->delete( (int) $req['id'] );
        return new WP_REST_Response( [ 'deleted' => $ok ], $ok ? 200 : 404 );
    }

    public function get_candles( WP_REST_Request $req ): WP_REST_Response {
        $index = $this->repo->find( (int) $req['id'] );
        if ( ! $index ) return $this->err( 'Không tìm thấy.', 404 );

        $tf     = strtoupper( sanitize_text_field( $req->get_param('timeframe') ) );
        $limit  = max( 1, min( 2000, (int) $req->get_param('limit') ) );
        $candles = $this->calc->get_candles( (int) $index['id'], $tf, $limit );

        // Sort ascending cho chart
        usort( $candles, static fn( $a, $b ) => (int)$a['event_time'] - (int)$b['event_time'] );

        return new WP_REST_Response( [
            'index'   => [
                'id'   => (int) $index['id'],
                'name' => $index['name'],
            ],
            'timeframe' => $tf,
            'candles'   => $candles,
        ], 200 );
    }

    public function backfill( WP_REST_Request $req ): WP_REST_Response {
        $index = $this->repo->find( (int) $req['id'] );
        if ( ! $index ) return $this->err( 'Không tìm thấy.', 404 );

        $tf    = strtoupper( sanitize_text_field( $req->get_param('timeframe') ) );
        $limit = (int) $req->get_param('limit');
        $reset = (bool) $req->get_param('reset');

        if ( $reset ) {
            $this->repo->reset_ohlc( (int) $index['id'] );
            $index = $this->repo->find( (int) $index['id'] );
        }

        // Reload sau reset
        $created = $this->calc->backfill( $index, $tf, $limit );

        return new WP_REST_Response( [
            'created'   => $created,
            'timeframe' => $tf,
            'message'   => "Đã tính {$created} phiên cho chỉ số \"{$index['name']}\".",
        ], 200 );
    }

    // ── Permission / helpers ──────────────────────────────────────────────────

    public function require_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    private function err( string $msg, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response( [ 'success' => false, 'message' => $msg ], $status );
    }

    private function create_args( bool $name_required = true ): array {
        return [
            'name'               => [ 'required' => $name_required, 'sanitize_callback' => 'sanitize_text_field' ],
            'description'        => [ 'default'  => '',   'sanitize_callback' => 'sanitize_textarea_field' ],
            'exchange'           => [ 'default'  => '',   'sanitize_callback' => 'sanitize_text_field' ],
            'id_icb2'            => [ 'default'  => 0,    'sanitize_callback' => 'absint' ],
            'symbol_scope'       => [ 'default'  => 'all','sanitize_callback' => 'sanitize_text_field' ],
            'scope_watchlist_id' => [ 'default'  => 0,    'sanitize_callback' => 'absint' ],
            'scope_custom_list'  => [ 'default'  => '',   'sanitize_callback' => 'sanitize_textarea_field' ],
            'is_active'          => [ 'default'  => true, 'sanitize_callback' => 'rest_sanitize_boolean' ],
        ];
    }
}
