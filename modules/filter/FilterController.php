<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterController {
    private $table;

    public function __construct(LCNI_FilterTable $table) {
        $this->table = $table;
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/filter/list', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'list_items'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function list_items(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) {
            return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        }

        $data = $this->table->query([
            'mode' => $request->get_param('mode'),
            'columns' => $request->get_param('columns'),
            'page' => $request->get_param('page'),
            'limit' => $request->get_param('limit'),
            'filters' => $request->get_param('filters'),
        ]);

        wp_send_json_success([
            'settings' => $this->table->get_settings(),
            'data' => $data,
        ]);
    }

    private function verify_rest_nonce(WP_REST_Request $request) {
        $nonce = $request->get_header('x_wp_nonce');

        return is_string($nonce) && wp_verify_nonce($nonce, 'wp_rest');
    }
}
