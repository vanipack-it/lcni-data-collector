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
        register_rest_route('lcni/v1', '/filter/default', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'set_default_filter'], 'permission_callback' => '__return_true']);
        register_rest_route('lcni/v1', '/filter/template/load', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'load_admin_template_filter'], 'permission_callback' => '__return_true']);
    }

    public function list_items(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);

        $filters = $this->service->sanitizeFiltersPublic($request->get_param('filters'), $this->table->get_settings()['criteria_columns'] ?? []);
        $skip_defaults = rest_sanitize_boolean($request->get_param('skip_defaults'));
        if (empty($filters) && !$skip_defaults) {
            $filters = $this->table->get_effective_default_saved_filters(is_user_logged_in() ? get_current_user_id() : 0);
        }

        $mode = sanitize_key((string) $request->get_param('mode'));

        $result = $this->service->getFilterResult([
            'visible_columns' => $mode === 'count_preview' ? ['symbol'] : $request->get_param('visible_columns'),
            'page' => $request->get_param('page'),
            'limit' => $request->get_param('limit'),
            'filters' => $filters,
        ]);

        if ($mode === 'count_preview') {
            return rest_ensure_response([
                'total' => (int) ($result['total'] ?? 0),
                'applied_filters' => $filters,
            ]);
        }

        $response = [
            'rows' => $this->table->render_tbody_rows($result['items'] ?? [], $result['columns'] ?? [], $this->table->get_settings()['add_button'] ?? []),
            'items' => $result['items'] ?? [],
            'columns' => $result['columns'] ?? [],
            'applied_filters' => $filters,
        ];
        if ($mode !== 'refresh') $response['total'] = (int) ($result['total'] ?? 0);

        return rest_ensure_response($response);
    }

    public function save_filter(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        if (!is_user_logged_in()) return new WP_Error('forbidden', 'Bạn cần đăng nhập để lưu bộ lọc.', ['status' => 403]);

        $user_id = get_current_user_id();
        if ($user_id <= 0) return new WP_Error('forbidden', 'Bạn cần đăng nhập để lưu bộ lọc.', ['status' => 403]);
        $filter_name = sanitize_text_field((string) $request->get_param('filter_name'));
        $filters = $this->service->sanitizeFiltersPublic($request->get_param('filters'), $this->table->get_settings()['criteria_columns'] ?? []);
        $config_json = wp_json_encode(['filters' => $filters]);
        if (!is_string($config_json) || $config_json === '') return new WP_Error('invalid_config', 'Không thể xử lý JSON.', ['status' => 400]);

        $this->wpdb->insert($this->saved_filters_table, ['user_id' => $user_id, 'filter_name' => $filter_name ?: 'Saved ' . current_time('mysql'), 'filter_config' => wp_slash($config_json)], ['%d', '%s', '%s']);

        $default_id = absint(get_user_meta($user_id, 'lcni_filter_default_saved_filter_id', true));
        if ($default_id <= 0) {
            update_user_meta($user_id, 'lcni_filter_default_saved_filter_id', (int) $this->wpdb->insert_id);
        }

        return $this->list_saved_filters($request);
    }

    public function list_saved_filters(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        $admin_templates = $this->get_admin_template_filters();
        $default_admin_template_id = absint(get_option('lcni_filter_default_admin_saved_filter_id', 0));

        if (!is_user_logged_in()) {
            return rest_ensure_response([
                'items' => [],
                'default_filter_id' => 0,
                'last_viewed_filter_id' => 0,
                'admin_templates' => $admin_templates,
                'default_admin_template_id' => $default_admin_template_id,
            ]);
        }
        $user_id = get_current_user_id();
        $sql = $this->wpdb->prepare("SELECT id, filter_name, created_at FROM {$this->saved_filters_table} WHERE user_id = %d ORDER BY id DESC", $user_id);
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return rest_ensure_response([
            'items' => is_array($rows) ? $rows : [],
            'default_filter_id' => absint(get_user_meta($user_id, 'lcni_filter_default_saved_filter_id', true)),
            'last_viewed_filter_id' => absint(get_user_meta($user_id, 'lcni_filter_last_viewed_saved_filter_id', true)),
            'admin_templates' => $admin_templates,
            'default_admin_template_id' => $default_admin_template_id,
        ]);
    }

    public function load_filter(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        if (!is_user_logged_in()) return new WP_Error('forbidden', 'Bạn cần đăng nhập.', ['status' => 403]);

        $id = absint($request->get_param('id'));
        $user_id = get_current_user_id();
        if ($user_id <= 0) return new WP_Error('forbidden', 'Bạn cần đăng nhập.', ['status' => 403]);
        $sql = $this->wpdb->prepare("SELECT filter_config FROM {$this->saved_filters_table} WHERE id = %d AND user_id = %d", $id, $user_id);
        $raw = $this->wpdb->get_var($sql);
        if ($id > 0) {
            update_user_meta($user_id, 'lcni_filter_last_viewed_saved_filter_id', $id);
        }
        $decoded = json_decode(wp_unslash((string) $raw), true);
        return rest_ensure_response(['id' => $id, 'config' => is_array($decoded) ? $decoded : ['filters' => []]]);
    }

    public function load_admin_template_filter(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);

        $id = absint($request->get_param('id'));
        if ($id <= 0) {
            return new WP_Error('not_found', 'Không tìm thấy template bộ lọc.', ['status' => 404]);
        }

        $templates = $this->get_admin_template_filters();
        $template_ids = array_map(static function ($item) {
            return absint($item['id'] ?? 0);
        }, $templates);
        if (!in_array($id, $template_ids, true)) {
            return new WP_Error('forbidden', 'Template bộ lọc không hợp lệ.', ['status' => 403]);
        }

        $raw = $this->wpdb->get_var($this->wpdb->prepare("SELECT filter_config FROM {$this->saved_filters_table} WHERE id = %d", $id));
        $decoded = json_decode(wp_unslash((string) $raw), true);

        return rest_ensure_response(['id' => $id, 'config' => is_array($decoded) ? $decoded : ['filters' => []]]);
    }

    private function get_admin_template_filters() {
        $admin_ids = get_users([
            'role__in' => ['administrator'],
            'fields' => 'ID',
        ]);
        if (!is_array($admin_ids) || empty($admin_ids)) {
            return [];
        }

        $admin_ids = array_values(array_filter(array_map('absint', $admin_ids)));
        if (empty($admin_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($admin_ids), '%d'));
        $sql = $this->wpdb->prepare(
            "SELECT id, user_id, filter_name, created_at FROM {$this->saved_filters_table} WHERE user_id IN ({$placeholders}) ORDER BY id DESC",
            ...$admin_ids
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function delete_filter(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        if (!is_user_logged_in()) return new WP_Error('forbidden', 'Bạn cần đăng nhập.', ['status' => 403]);

        $id = absint($request->get_param('id'));
        $user_id = get_current_user_id();
        if ($user_id <= 0) return new WP_Error('forbidden', 'Bạn cần đăng nhập.', ['status' => 403]);
        $this->wpdb->delete($this->saved_filters_table, ['id' => $id, 'user_id' => $user_id], ['%d', '%d']);

        $default_id = absint(get_user_meta($user_id, 'lcni_filter_default_saved_filter_id', true));
        if ($default_id === $id) {
            delete_user_meta($user_id, 'lcni_filter_default_saved_filter_id');
        }

        return $this->list_saved_filters($request);
    }

    public function set_default_filter(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        if (!is_user_logged_in()) return new WP_Error('forbidden', 'Bạn cần đăng nhập.', ['status' => 403]);

        $id = absint($request->get_param('id'));
        $user_id = get_current_user_id();
        if ($user_id <= 0) return new WP_Error('forbidden', 'Bạn cần đăng nhập.', ['status' => 403]);

        if ($id > 0) {
            $sql = $this->wpdb->prepare("SELECT id FROM {$this->saved_filters_table} WHERE id = %d AND user_id = %d", $id, $user_id);
            $exists = (int) $this->wpdb->get_var($sql);
            if ($exists <= 0) return new WP_Error('not_found', 'Không tìm thấy bộ lọc.', ['status' => 404]);
            update_user_meta($user_id, 'lcni_filter_default_saved_filter_id', $id);
        } else {
            delete_user_meta($user_id, 'lcni_filter_default_saved_filter_id');
        }

        return rest_ensure_response(['default_filter_id' => $id]);
    }

    private function verify_rest_nonce(WP_REST_Request $request) {
        $nonce = $request->get_header('x_wp_nonce');
        return is_string($nonce) && wp_verify_nonce($nonce, 'wp_rest');
    }
}
