<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterAjax {
    private $table;
    private $service;
    private $wpdb;
    private $saved_filters_table;

    public function __construct(LCNI_FilterTable $table, FilterService $service) {
        global $wpdb;
        $this->table = $table;
        $this->service = $service;
        $this->wpdb = $wpdb;
        $this->saved_filters_table = $this->wpdb->prefix . 'lcni_saved_filters';
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/filter/list', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'list_items'], 'permission_callback' => '__return_true'],
            ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'list_saved_filters'], 'permission_callback' => '__return_true'],
        ]);
        register_rest_route('lcni/v1', '/filter/save', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'save_filter'], 'permission_callback' => '__return_true']);
        register_rest_route('lcni/v1', '/filter/load', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'load_filter'], 'permission_callback' => '__return_true']);
        register_rest_route('lcni/v1', '/filter/delete', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'delete_filter'], 'permission_callback' => '__return_true']);
    }

    public function list_items(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);

        $result = $this->service->getFilterResult([
            'visible_columns' => $request->get_param('visible_columns'),
            'page' => $request->get_param('page'),
            'limit' => $request->get_param('limit'),
            'filters' => $request->get_param('filters'),
        ]);

        $response = ['rows' => $this->table->render_tbody_rows($result['items'] ?? [], $result['columns'] ?? [], $this->table->get_settings()['add_button'] ?? [])];
        if (sanitize_key((string) $request->get_param('mode')) !== 'refresh') $response['total'] = (int) ($result['total'] ?? 0);

        return rest_ensure_response($response);
    }

    public function save_filter(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        if (!is_user_logged_in()) return new WP_Error('forbidden', 'Bạn cần đăng nhập để lưu bộ lọc.', ['status' => 403]);

        $user_id = get_current_user_id();
        $filter_name = sanitize_text_field((string) $request->get_param('filter_name'));
        $filters = $this->service->sanitizeFiltersPublic($request->get_param('filters'), $this->table->get_settings()['criteria_columns'] ?? []);
        $config_json = wp_json_encode(['filters' => $filters]);
        if (!is_string($config_json) || $config_json === '') return new WP_Error('invalid_config', 'Không thể xử lý JSON.', ['status' => 400]);

        $this->wpdb->insert($this->saved_filters_table, ['user_id' => $user_id, 'filter_name' => $filter_name ?: 'Saved ' . current_time('mysql'), 'filter_config' => wp_slash($config_json)], ['%d', '%s', '%s']);
        return $this->list_saved_filters($request);
    }

    public function list_saved_filters(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        if (!is_user_logged_in()) return rest_ensure_response(['items' => []]);
        $user_id = get_current_user_id();
        $sql = $this->wpdb->prepare("SELECT id, filter_name, created_at FROM {$this->saved_filters_table} WHERE user_id = %d ORDER BY id DESC", $user_id);
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return rest_ensure_response(['items' => is_array($rows) ? $rows : []]);
    }

    public function load_filter(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        if (!is_user_logged_in()) return new WP_Error('forbidden', 'Bạn cần đăng nhập.', ['status' => 403]);

        $id = absint($request->get_param('id'));
        $user_id = get_current_user_id();
        $sql = $this->wpdb->prepare("SELECT filter_config FROM {$this->saved_filters_table} WHERE id = %d AND user_id = %d", $id, $user_id);
        $raw = $this->wpdb->get_var($sql);
        $decoded = json_decode((string) $raw, true);
        return rest_ensure_response(['id' => $id, 'config' => is_array($decoded) ? $decoded : ['filters' => []]]);
    }

    public function delete_filter(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        if (!is_user_logged_in()) return new WP_Error('forbidden', 'Bạn cần đăng nhập.', ['status' => 403]);

        $id = absint($request->get_param('id'));
        $this->wpdb->delete($this->saved_filters_table, ['id' => $id, 'user_id' => get_current_user_id()], ['%d', '%d']);
        return $this->list_saved_filters($request);
    }

    private function verify_rest_nonce(WP_REST_Request $request) {
        $nonce = $request->get_header('x_wp_nonce');
        return is_string($nonce) && wp_verify_nonce($nonce, 'wp_rest');
    }
}
