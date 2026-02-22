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
        register_rest_route('lcni/v1', '/watchlist/list', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'list_watchlist'], 'permission_callback' => [$this, 'can_access_watchlist']]);
        register_rest_route('lcni/v1', '/watchlist/load', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'list_watchlist'], 'permission_callback' => [$this, 'can_access_watchlist']]);
        register_rest_route('lcni/v1', '/watchlist/create', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'create_watchlist'], 'permission_callback' => [$this, 'can_access_watchlist']]);
        register_rest_route('lcni/v1', '/watchlist/delete', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'delete_watchlist'], 'permission_callback' => [$this, 'can_access_watchlist']]);
        register_rest_route('lcni/v1', '/watchlist/add-symbol', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'add_symbol'], 'permission_callback' => [$this, 'can_access_watchlist']]);
        register_rest_route('lcni/v1', '/watchlist/remove-symbol', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'remove_symbol'], 'permission_callback' => [$this, 'can_access_watchlist']]);
        register_rest_route('lcni/v1', '/watchlist/add', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'add_symbol'], 'permission_callback' => [$this, 'can_access_watchlist']]);
        register_rest_route('lcni/v1', '/watchlist/remove', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'remove_symbol'], 'permission_callback' => [$this, 'can_access_watchlist']]);
        register_rest_route('lcni/v1', '/watchlist/settings', [[ 'methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_settings'], 'permission_callback' => [$this, 'can_access_watchlist']],[ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'save_settings'], 'permission_callback' => [$this, 'can_access_watchlist']]]);
    }

    public function can_access_watchlist() { return is_user_logged_in(); }

    public function list_watchlist(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);

        $user_id = get_current_user_id();
        $device = $request->get_param('device') === 'mobile' ? 'mobile' : 'desktop';
        $watchlist_id = absint($request->get_param('watchlist_id'));
        if ($watchlist_id > 0) {
            $set = $this->service->set_active_watchlist($user_id, $watchlist_id);
            if (is_wp_error($set)) return $set;
        }

        $active_watchlist_id = $watchlist_id > 0 ? $watchlist_id : $this->service->get_active_watchlist_id($user_id);
        $columns = $request->get_param('columns');
        if (!is_array($columns)) $columns = $this->service->get_user_columns($user_id, $device);
        $data = $this->service->get_watchlist($user_id, $columns, $device, $active_watchlist_id);

        $mode = sanitize_key((string) $request->get_param('mode'));
        if ($mode === 'refresh') {
            wp_send_json_success(['rows' => $this->render_tbody_rows($data['items'], $data['columns']), 'symbols' => $data['symbols']]);
        }

        wp_send_json_success([
            'watchlists' => $this->service->list_watchlists($user_id),
            'active_watchlist_id' => $active_watchlist_id,
            'allowed_columns' => $this->service->get_allowed_columns(),
            'columns' => $data['columns'],
            'column_labels' => $data['column_labels'],
            'items' => $data['items'],
            'symbols' => $data['symbols'],
            'settings' => $this->service->get_settings(),
        ]);
    }

    public function create_watchlist(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        $result = $this->service->create_watchlist(get_current_user_id(), $request->get_param('name'));
        if (is_wp_error($result)) return $result;
        wp_send_json_success($result);
    }

    public function delete_watchlist(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        $result = $this->service->delete_watchlist(get_current_user_id(), $request->get_param('watchlist_id'));
        if (is_wp_error($result)) return $result;
        wp_send_json_success($result);
    }

    public function add_symbol(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        $result = $this->service->add_symbol(get_current_user_id(), $request->get_param('symbol'), absint($request->get_param('watchlist_id')));
        if (is_wp_error($result)) return $result;
        wp_send_json_success($result);
    }

    public function remove_symbol(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        $result = $this->service->remove_symbol(get_current_user_id(), $request->get_param('symbol'), absint($request->get_param('watchlist_id')));
        if (is_wp_error($result)) return $result;
        wp_send_json_success($result);
    }

    public function get_settings(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        $user_id = get_current_user_id();
        wp_send_json_success(['allowed_columns' => $this->service->get_allowed_columns(), 'columns' => $this->service->get_user_columns($user_id, $request->get_param('device') === 'mobile' ? 'mobile' : 'desktop'), 'settings' => $this->service->get_settings()]);
    }

    public function save_settings(WP_REST_Request $request) {
        if (!$this->verify_rest_nonce($request)) return new WP_Error('invalid_nonce', 'Nonce không hợp lệ.', ['status' => 403]);
        $columns = $request->get_param('columns');
        $saved = $this->service->save_user_columns(get_current_user_id(), is_array($columns) ? $columns : []);
        wp_send_json_success(['columns' => $saved, 'allowed_columns' => $this->service->get_allowed_columns()]);
    }

    private function render_tbody_rows(array $items, array $columns) {
        $settings = $this->service->get_settings();
        $rules = isset($settings['value_color_rules']) && is_array($settings['value_color_rules']) ? $settings['value_color_rules'] : [];
        $html = '';
        foreach ($items as $row) {
            $symbol = isset($row['symbol']) ? (string) $row['symbol'] : '';
            $html .= '<tr data-row-symbol="' . esc_attr($symbol) . '">';
            foreach ($columns as $index => $column) {
                $sticky = ($index === 0 && $column === 'symbol') ? ' class="is-sticky-col"' : '';
                if ($column === 'symbol') {
                    $html .= '<td' . $sticky . '><span class="lcni-watchlist-symbol">' . esc_html($symbol) . '</span><button type="button" class="lcni-watchlist-add lcni-btn lcni-btn-btn_watchlist_add is-active" data-lcni-watchlist-add data-symbol="' . esc_attr($symbol) . '" aria-label="Remove from watchlist">' . LCNI_Button_Style_Config::build_button_content('btn_watchlist_add', '') . '</button></td>';
                    continue;
                }
                $value = isset($row[$column]) ? (string) $row[$column] : '';
                $style = $this->resolve_cell_style($column, $value, $rules);
                $html .= '<td' . $sticky . ($style ? ' style="' . esc_attr($style) . '"' : '') . '>' . esc_html($value) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html;
    }

    private function resolve_cell_style($column, $value, array $rules) {
        foreach ($rules as $rule) {
            if (!is_array($rule) || ($rule['column'] ?? '') !== $column) continue;
            if (!$this->match_rule($value, (string) ($rule['operator'] ?? ''), $rule['value'] ?? '')) continue;
            $bg = sanitize_hex_color((string) ($rule['bg_color'] ?? ''));
            $text = sanitize_hex_color((string) ($rule['text_color'] ?? ''));
            if (!$bg || !$text) return '';
            return 'background:' . $bg . ';color:' . $text . ';';
        }
        return '';
    }

    private function match_rule($raw_value, $operator, $expected) {
        $left_num = is_numeric($raw_value) ? (float) $raw_value : null;
        $right_num = is_numeric($expected) ? (float) $expected : null;
        $left = $left_num !== null && $right_num !== null ? $left_num : (string) $raw_value;
        $right = $left_num !== null && $right_num !== null ? $right_num : (string) $expected;
        if ($operator === '>') return $left > $right;
        if ($operator === '>=') return $left >= $right;
        if ($operator === '<') return $left < $right;
        if ($operator === '<=') return $left <= $right;
        if ($operator === '=') return $left === $right;
        if ($operator === '!=') return $left !== $right;
        return false;
    }

    private function verify_rest_nonce(WP_REST_Request $request) {
        $nonce = $request->get_header('x_wp_nonce');
        return is_string($nonce) && wp_verify_nonce($nonce, 'wp_rest');
    }
}
