<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_UserController {

    private $membership;
    private $watchlist;
    private $notifications;

    public function __construct(LCNI_UserMembershipService $membership, LCNI_WatchlistService $watchlist, LCNI_SignalNotificationService $notifications) {
        $this->membership = $membership;
        $this->watchlist = $watchlist;
        $this->notifications = $notifications;
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/auth/register', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'register'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lcni/v1', '/auth/login', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lcni/v1', '/membership', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_membership'],
                'permission_callback' => [$this, 'is_logged_in'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'set_membership'],
                'permission_callback' => [$this, 'can_manage_users'],
            ],
        ]);

        register_rest_route('lcni/v1', '/watchlist', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_watchlist'],
                'permission_callback' => [$this, 'is_logged_in'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'add_to_watchlist'],
                'permission_callback' => [$this, 'is_logged_in'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'remove_from_watchlist'],
                'permission_callback' => [$this, 'is_logged_in'],
            ],
        ]);

        register_rest_route('lcni/v1', '/watchlist/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_watchlist_settings'],
                'permission_callback' => [$this, 'is_logged_in'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_watchlist_settings'],
                'permission_callback' => [$this, 'is_logged_in'],
            ],
        ]);

        register_rest_route('lcni/v1', '/watchlist/admin-settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_watchlist_admin_settings'],
                'permission_callback' => [$this, 'can_manage_options'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_watchlist_admin_settings'],
                'permission_callback' => [$this, 'can_manage_options'],
            ],
        ]);

        register_rest_route('lcni/v1', '/notifications', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_notifications'],
                'permission_callback' => [$this, 'is_logged_in'],
            ],
        ]);

        register_rest_route('lcni/v1', '/notifications/preferences', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_notification_preferences'],
                'permission_callback' => [$this, 'is_logged_in'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_notification_preferences'],
                'permission_callback' => [$this, 'is_logged_in'],
            ],
        ]);

        register_rest_route('lcni/v1', '/notifications/(?P<id>\d+)/read', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'mark_notification_read'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);
    }

    public function register(WP_REST_Request $request) {
        return rest_ensure_response($this->membership->register(
            $request->get_param('email'),
            $request->get_param('password'),
            $request->get_param('display_name'),
            $request->get_param('tier')
        ));
    }

    public function login(WP_REST_Request $request) {
        return rest_ensure_response($this->membership->login(
            $request->get_param('email'),
            $request->get_param('password'),
            $request->get_param('remember')
        ));
    }

    public function get_membership() {
        return rest_ensure_response($this->membership->get_user_profile(get_current_user_id()));
    }

    public function set_membership(WP_REST_Request $request) {
        $user_id = (int) $request->get_param('user_id');
        $tier = $this->membership->set_tier($user_id, $request->get_param('tier'));

        return rest_ensure_response(['user_id' => $user_id, 'tier' => $tier]);
    }

    public function get_watchlist() {
        return rest_ensure_response($this->watchlist->get_watchlist(get_current_user_id()));
    }

    public function add_to_watchlist(WP_REST_Request $request) {
        return rest_ensure_response($this->watchlist->add_symbol(get_current_user_id(), $request->get_param('symbol'), $request->get_param('source')));
    }

    public function remove_from_watchlist(WP_REST_Request $request) {
        return rest_ensure_response($this->watchlist->remove_symbol(get_current_user_id(), $request->get_param('symbol')));
    }

    public function get_watchlist_settings() {
        $admin = $this->watchlist->get_admin_settings();
        $user = get_user_meta(get_current_user_id(), LCNI_WatchlistService::META_FIELD_SETTINGS, true);

        return rest_ensure_response([
            'allowed_fields' => $admin['allowed_fields'],
            'supported_fields' => $admin['supported_fields'],
            'selected_fields' => is_array($user) ? $user : $admin['allowed_fields'],
        ]);
    }

    public function save_watchlist_settings(WP_REST_Request $request) {
        return rest_ensure_response([
            'fields' => $this->watchlist->save_user_fields(get_current_user_id(), $request->get_param('fields')),
        ]);
    }

    public function get_watchlist_admin_settings() {
        return rest_ensure_response($this->watchlist->get_admin_settings());
    }

    public function save_watchlist_admin_settings(WP_REST_Request $request) {
        return rest_ensure_response($this->watchlist->save_admin_settings((array) $request->get_json_params()));
    }

    public function list_notifications(WP_REST_Request $request) {
        $user_id = get_current_user_id();

        return rest_ensure_response([
            'items' => $this->notifications->list_notifications($user_id, (int) $request->get_param('limit')),
            'unread_count' => $this->notifications->unread_count($user_id),
        ]);
    }

    public function get_notification_preferences() {
        return rest_ensure_response($this->notifications->get_preferences(get_current_user_id()));
    }

    public function save_notification_preferences(WP_REST_Request $request) {
        return rest_ensure_response($this->notifications->save_preferences(get_current_user_id(), (array) $request->get_json_params()));
    }

    public function mark_notification_read(WP_REST_Request $request) {
        $id = (int) $request->get_param('id');
        $this->notifications->mark_read(get_current_user_id(), $id);

        return rest_ensure_response(['id' => $id, 'status' => 'read']);
    }

    public function is_logged_in() {
        return is_user_logged_in();
    }

    public function can_manage_options() {
        return current_user_can('manage_options');
    }

    public function can_manage_users() {
        return current_user_can('list_users');
    }
}
