<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistController {

    private $service;

    public function __construct(LCNI_WatchlistService $service) {
        $this->service = $service;
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/watchlist/list', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'list_watchlist'],
            'permission_callback' => [$this, 'can_access_watchlist'],
        ]);

        register_rest_route('lcni/v1', '/watchlist/add', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'add_symbol'],
            'permission_callback' => [$this, 'can_access_watchlist'],
        ]);

        register_rest_route('lcni/v1', '/watchlist/remove', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'remove_symbol'],
            'permission_callback' => [$this, 'can_access_watchlist'],
        ]);

        register_rest_route('lcni/v1', '/watchlist/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [$this, 'can_access_watchlist'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_settings'],
                'permission_callback' => [$this, 'can_access_watchlist'],
            ],
        ]);
    }

    public function can_access_watchlist() {
        return is_user_logged_in();
    }

    public function list_watchlist(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) {
            return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        }

        $user_id = get_current_user_id();
        $device = $request->get_param('device') === 'mobile' ? 'mobile' : 'desktop';
        $columns = $request->get_param('columns');
        if (!is_array($columns)) {
            $columns = $this->service->get_user_columns($user_id, $device);
        }

        $data = $this->service->get_watchlist($user_id, $columns, $device);

        return rest_ensure_response([
            'allowed_columns' => $this->service->get_allowed_columns(),
            'columns' => $data['columns'],
            'column_labels' => $data['column_labels'],
            'items' => $data['items'],
            'symbols' => $data['symbols'],
        ]);
    }

    public function add_symbol(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) {
            return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        }

        $result = $this->service->add_symbol(get_current_user_id(), $request->get_param('symbol'));

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function remove_symbol(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) {
            return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        }

        $result = $this->service->remove_symbol(get_current_user_id(), $request->get_param('symbol'));

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public function get_settings(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) {
            return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        }

        $user_id = get_current_user_id();

        return rest_ensure_response([
            'allowed_columns' => $this->service->get_allowed_columns(),
            'columns' => $this->service->get_user_columns($user_id, $request->get_param('device') === 'mobile' ? 'mobile' : 'desktop'),
        ]);
    }

    public function save_settings(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) {
            return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        }

        $columns = $request->get_param('columns');
        $saved = $this->service->save_user_columns(get_current_user_id(), is_array($columns) ? $columns : []);

        return rest_ensure_response([
            'columns' => $saved,
            'allowed_columns' => $this->service->get_allowed_columns(),
        ]);
    }

    private function verify_rest_nonce(WP_REST_Request $request) {
        $nonce = $request->get_header('x_wp_nonce');

        return is_string($nonce) && wp_verify_nonce($nonce, 'wp_rest');
    }
}
