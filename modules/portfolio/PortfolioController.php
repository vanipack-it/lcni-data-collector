<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Portfolio_Controller {

    private $service;

    public function __construct(LCNI_Portfolio_Service $service) {
        $this->service = $service;
    }

    public function register_routes() {
        $ns = 'lcni/v1';

        // Portfolios
        register_rest_route($ns, '/portfolio/list',   ['methods' => 'GET',  'callback' => [$this, 'list_portfolios'],   'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/create', ['methods' => 'POST', 'callback' => [$this, 'create_portfolio'],  'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/update', ['methods' => 'POST', 'callback' => [$this, 'update_portfolio'],  'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/delete', ['methods' => 'POST', 'callback' => [$this, 'delete_portfolio'],  'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/set-default', ['methods' => 'POST', 'callback' => [$this, 'set_default'], 'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/list-with-meta', ['methods' => 'GET',  'callback' => [$this, 'list_with_meta'],   'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/sync-dnse',      ['methods' => 'POST', 'callback' => [$this, 'sync_dnse'],        'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/set-combined',   ['methods' => 'POST', 'callback' => [$this, 'set_combined'],     'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/create-combined',['methods' => 'POST', 'callback' => [$this, 'create_combined'],  'permission_callback' => [$this, 'auth']]);

        // Portfolio data (holdings + P&L)
        register_rest_route($ns, '/portfolio/data',   ['methods' => 'GET',  'callback' => [$this, 'get_data'],          'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/equity-curve', ['methods' => 'GET', 'callback' => [$this, 'equity_curve'], 'permission_callback' => [$this, 'auth']]);

        // Transactions
        register_rest_route($ns, '/portfolio/tx/add',    ['methods' => 'POST', 'callback' => [$this, 'add_tx'],    'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/tx/update', ['methods' => 'POST', 'callback' => [$this, 'update_tx'], 'permission_callback' => [$this, 'auth']]);
        register_rest_route($ns, '/portfolio/tx/delete', ['methods' => 'POST', 'callback' => [$this, 'delete_tx'], 'permission_callback' => [$this, 'auth']]);
    }

    public function auth() {
        return is_user_logged_in();
    }

    // =========================================================
    // Portfolios
    // =========================================================

    public function list_portfolios(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id = get_current_user_id();
        $portfolios = $this->service->get_portfolios($user_id);
        return wp_send_json_success(['portfolios' => $portfolios]);
    }

    public function create_portfolio(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id = get_current_user_id();
        $name = sanitize_text_field($req->get_param('name') ?? '');
        $desc = sanitize_textarea_field($req->get_param('description') ?? '');
        $id   = $this->service->create_portfolio($user_id, $name, $desc);
        return wp_send_json_success(['id' => $id]);
    }

    public function update_portfolio(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id = get_current_user_id();
        $this->service->update_portfolio(
            absint($req->get_param('portfolio_id')),
            $user_id,
            sanitize_text_field($req->get_param('name') ?? ''),
            sanitize_textarea_field($req->get_param('description') ?? '')
        );
        return wp_send_json_success();
    }

    public function delete_portfolio(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id = get_current_user_id();
        $result  = $this->service->delete_portfolio(absint($req->get_param('portfolio_id')), $user_id);
        if (is_wp_error($result)) return wp_send_json_error($result->get_error_message());
        return wp_send_json_success();
    }

    public function set_default(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $this->service->set_default(absint($req->get_param('portfolio_id')), get_current_user_id());
        return wp_send_json_success();
    }

    // =========================================================
    // Portfolio data
    // =========================================================

    public function get_data(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id = get_current_user_id();
        $data    = $this->service->get_portfolio_data(absint($req->get_param('portfolio_id')), $user_id);
        if (!$data) return wp_send_json_error('Danh mục không tồn tại.');
        return wp_send_json_success($data);
    }

    public function equity_curve(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id = get_current_user_id();
        $limit   = min(365, max(7, absint($req->get_param('limit') ?? 90)));
        $data    = $this->service->get_equity_curve(absint($req->get_param('portfolio_id')), $user_id, $limit);
        return wp_send_json_success(['curve' => $data]);
    }

    // =========================================================
    // Transactions
    // =========================================================

    public function add_tx(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id = get_current_user_id();
        $result  = $this->service->add_transaction(
            absint($req->get_param('portfolio_id')),
            $user_id,
            $this->extract_tx_data($req)
        );
        if (is_wp_error($result)) return wp_send_json_error($result->get_error_message());
        return wp_send_json_success(['tx_id' => $result]);
    }

    public function update_tx(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id = get_current_user_id();
        $result  = $this->service->update_transaction(
            absint($req->get_param('tx_id')),
            $user_id,
            $this->extract_tx_data($req)
        );
        if (is_wp_error($result)) return wp_send_json_error($result->get_error_message());
        return wp_send_json_success();
    }

    public function delete_tx(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $result = $this->service->delete_transaction(absint($req->get_param('tx_id')), get_current_user_id());
        if (is_wp_error($result)) return wp_send_json_error($result->get_error_message());
        return wp_send_json_success();
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function extract_tx_data(WP_REST_Request $req) {
        return [
            'symbol'     => $req->get_param('symbol') ?? '',
            'type'       => $req->get_param('type') ?? 'buy',
            'trade_date' => $req->get_param('trade_date') ?? '',
            'quantity'   => $req->get_param('quantity') ?? 0,
            'price'      => $req->get_param('price') ?? 0,
            'fee'        => $req->get_param('fee') ?? 0,
            'tax'        => $req->get_param('tax') ?? 0,
            'note'       => $req->get_param('note') ?? '',
        ];
    }

    private function nonce_ok(WP_REST_Request $req) {
        $nonce = $req->get_header('X-WP-Nonce') ?: ($req->get_param('_wpnonce') ?? '');
        return (bool) wp_verify_nonce($nonce, 'wp_rest');
    }

    private function err_nonce() {
        return wp_send_json_error('Nonce không hợp lệ.', 403);
    }

    public function list_with_meta(WP_REST_Request $req) {
        $user_id = get_current_user_id();
        $portfolios = method_exists($this->service->repo ?? null, 'get_portfolios_with_meta')
            ? $this->service->repo->get_portfolios_with_meta($user_id)
            : $this->service->get_portfolios($user_id);
        return new WP_REST_Response(['success' => true, 'data' => ['portfolios' => $portfolios]]);
    }

    /**
     * Đồng bộ lệnh DNSE đã khớp vào portfolio.
     * Được gọi tự động sau sync DNSE hoặc user bấm thủ công.
     */
    public function sync_dnse(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id    = get_current_user_id();
        $account_no = sanitize_text_field($req->get_param('account_no') ?? '');
        $type_name  = sanitize_text_field($req->get_param('account_type_name') ?? '');

        if (!$account_no) return $this->err('Missing account_no');
        if (!method_exists($this->service, 'sync_dnse_orders_to_portfolio')) {
            return $this->err('sync_dnse_orders_to_portfolio not available');
        }
        $count = $this->service->sync_dnse_orders_to_portfolio($user_id, $account_no, $type_name);
        return new WP_REST_Response(['success' => true, 'data' => ['synced' => $count,
            'message' => "Đã đồng bộ {$count} lệnh từ DNSE vào danh mục."]]);
    }

    /**
     * Tạo portfolio combined từ danh sách portfolio IDs.
     */
    public function create_combined(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id  = get_current_user_id();
        $name     = sanitize_text_field($req->get_param('name') ?? 'Tổng hợp');
        $ids      = array_filter(array_map('absint', (array)($req->get_param('portfolio_ids') ?? [])));
        if (empty($ids)) return $this->err('Cần chọn ít nhất 1 danh mục.');

        $portfolio_id = $this->service->create_portfolio($user_id, $name, 'Danh mục tổng hợp nhiều nguồn.');
        if (!$portfolio_id) return $this->err('Không thể tạo danh mục.');

        // Cập nhật source + combined_ids
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'lcni_portfolios',
            ['source' => 'combined', 'dnse_combined_ids' => implode(',', $ids)],
            ['id' => $portfolio_id, 'user_id' => $user_id],
            ['%s', '%s'], ['%d', '%d']
        );

        return new WP_REST_Response(['success' => true, 'data' => ['id' => $portfolio_id, 'name' => $name]]);
    }

    /**
     * Cập nhật danh sách IDs cho portfolio combined.
     */
    public function set_combined(WP_REST_Request $req) {
        if (!$this->nonce_ok($req)) return $this->err_nonce();
        $user_id      = get_current_user_id();
        $portfolio_id = absint($req->get_param('portfolio_id'));
        $ids          = array_filter(array_map('absint', (array)($req->get_param('portfolio_ids') ?? [])));
        if (!$portfolio_id) return $this->err('Missing portfolio_id');

        if (method_exists($this->service->repo ?? null, 'update_combined_ids')) {
            $this->service->repo->update_combined_ids($portfolio_id, $user_id, $ids);
        }
        return new WP_REST_Response(['success' => true]);
    }

}
