<?php
/**
 * LCNI_InboxRestAPI
 * REST endpoints:
 *   GET  /lcni/v1/inbox              — danh sách (page, per_page, filter)
 *   GET  /lcni/v1/inbox/count        — unread count
 *   GET  /lcni/v1/inbox/{id}         — chi tiết + tự mark read
 *   POST /lcni/v1/inbox/mark-read    — { ids: [...] | 'all' }
 *   GET  /lcni/v1/inbox/prefs        — user prefs
 *   POST /lcni/v1/inbox/prefs        — save user prefs
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_InboxRestAPI {

    const NS = 'lcni/v1';

    public function register_hooks() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( self::NS, '/inbox', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_list' ],
            'permission_callback' => [ $this, 'is_logged_in' ],
        ] );
        register_rest_route( self::NS, '/inbox/count', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_count' ],
            'permission_callback' => [ $this, 'is_logged_in' ],
        ] );
        register_rest_route( self::NS, '/inbox/(?P<id>\\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_single' ],
            'permission_callback' => [ $this, 'is_logged_in' ],
        ] );
        register_rest_route( self::NS, '/inbox/mark-read', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'mark_read' ],
            'permission_callback' => [ $this, 'is_logged_in' ],
        ] );
        register_rest_route( self::NS, '/inbox/prefs', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_prefs' ],  'permission_callback' => [ $this, 'is_logged_in' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'save_prefs' ], 'permission_callback' => [ $this, 'is_logged_in' ] ],
        ] );
    }

    public function is_logged_in() {
        return is_user_logged_in();
    }

    // ── GET /inbox ────────────────────────────────────────────────────────────

    public function get_list( WP_REST_Request $req ) {
        $uid    = get_current_user_id();
        $page   = max( 1, (int) $req->get_param( 'page' ) );
        $per    = max( 1, min( 50, (int) ( $req->get_param( 'per_page' ) ?: 20 ) ) );
        $filter = in_array( $req->get_param( 'filter' ), [ 'all', 'unread', 'read' ], true )
                  ? $req->get_param( 'filter' ) : 'all';
        $type   = sanitize_key( (string) ( $req->get_param( 'type' ) ?: '' ) );

        $rows   = LCNI_InboxDB::get_list( $uid, $per, ( $page - 1 ) * $per, $filter, $type );
        $count  = LCNI_InboxDB::get_unread_count( $uid );

        return rest_ensure_response( [
            'items'        => array_map( [ $this, 'format_row' ], $rows ),
            'unread_count' => $count,
        ] );
    }

    // ── GET /inbox/count ──────────────────────────────────────────────────────

    public function get_count() {
        return rest_ensure_response( [
            'unread_count' => LCNI_InboxDB::get_unread_count( get_current_user_id() ),
        ] );
    }

    // ── GET /inbox/{id} ───────────────────────────────────────────────────────

    public function get_single( WP_REST_Request $req ) {
        $uid = get_current_user_id();
        $id  = (int) $req->get_param( 'id' );
        $row = LCNI_InboxDB::get_single( $id, $uid );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Notification not found.', [ 'status' => 404 ] );
        }
        // Auto mark read
        if ( ! $row['is_read'] ) {
            LCNI_InboxDB::mark_read( $uid, [ $id ] );
            $row['is_read'] = 1;
        }
        return rest_ensure_response( $this->format_row( $row ) );
    }

    // ── POST /inbox/mark-read ─────────────────────────────────────────────────

    public function mark_read( WP_REST_Request $req ) {
        $uid = get_current_user_id();
        $ids = $req->get_param( 'ids' );

        if ( $ids === 'all' || $ids === null ) {
            LCNI_InboxDB::mark_read( $uid, 'all' );
        } elseif ( is_array( $ids ) ) {
            LCNI_InboxDB::mark_read( $uid, $ids );
        }

        return rest_ensure_response( [
            'unread_count' => LCNI_InboxDB::get_unread_count( $uid ),
        ] );
    }

    // ── GET/POST /inbox/prefs ─────────────────────────────────────────────────

    public function get_prefs() {
        $uid   = get_current_user_id();
        $types = LCNI_InboxDB::TYPES;
        $admin = LCNI_InboxDB::get_admin_enabled_types();
        $prefs = LCNI_InboxDB::get_user_prefs( $uid );

        $result = [];
        foreach ( $types as $key => $label ) {
            if ( ! in_array( $key, $admin, true ) ) continue;
            $result[] = [
                'type'    => $key,
                'label'   => $label,
                'enabled' => $prefs[ $key ] ?? true,
            ];
        }
        return rest_ensure_response( $result );
    }

    public function save_prefs( WP_REST_Request $req ) {
        $uid   = get_current_user_id();
        $prefs = (array) ( $req->get_param( 'prefs' ) ?? [] );
        // prefs = [ 'new_signal' => true, 'system' => false, ... ]
        LCNI_InboxDB::save_user_prefs( $uid, $prefs );
        return rest_ensure_response( [ 'saved' => true ] );
    }

    // ── Format ────────────────────────────────────────────────────────────────

    private function format_row( array $row ) {
        return [
            'id'         => (int) $row['id'],
            'type'       => $row['type'],
            'type_label' => LCNI_InboxDB::get_type_label( $row['type'] ),
            'title'      => $row['title'],
            'body'       => $row['body'],
            'url'        => $row['url'],
            'meta'       => json_decode( $row['meta'] ?: '{}', true ),
            'is_read'    => (bool) $row['is_read'],
            'created_at' => $row['created_at'],
            'time_ago'   => $this->time_ago( $row['created_at'] ),
        ];
    }

    private function time_ago( $datetime ) {
        $diff = time() - strtotime( $datetime );
        if ( $diff < 60 )       return 'vừa xong';
        if ( $diff < 3600 )     return ( (int) floor( $diff / 60 ) ) . ' phút trước';
        if ( $diff < 86400 )    return ( (int) floor( $diff / 3600 ) ) . ' giờ trước';
        if ( $diff < 604800 )   return ( (int) floor( $diff / 86400 ) ) . ' ngày trước';
        return date( 'd/m/Y', strtotime( $datetime ) );
    }
}
