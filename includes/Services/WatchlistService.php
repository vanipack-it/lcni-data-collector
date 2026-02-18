<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistService {

    const META_FIELD_SETTINGS = 'lcni_watchlist_user_fields';

    private $repo;
    private $membership;

    public function __construct(LCNI_UserWatchlistRepository $repo, LCNI_UserMembershipService $membership) {
        $this->repo = $repo;
        $this->membership = $membership;
    }

    public function add_symbol($user_id, $symbol, $source = 'manual') {
        $symbol = $this->sanitize_symbol($symbol);
        if ($symbol === '') {
            return new WP_Error('invalid_symbol', 'Mã cổ phiếu không hợp lệ.', ['status' => 400]);
        }

        $max = $this->membership->get_tier($user_id) === 'premium' ? 200 : 30;
        $current = $this->repo->get_symbols($user_id);

        if (!in_array($symbol, $current, true) && count($current) >= $max) {
            return new WP_Error('watchlist_limit_exceeded', sprintf('Gói hiện tại chỉ cho tối đa %d mã.', $max), ['status' => 403]);
        }

        $this->repo->add_symbol($user_id, $symbol, $source);

        return ['symbol' => $symbol];
    }

    public function remove_symbol($user_id, $symbol) {
        $symbol = $this->sanitize_symbol($symbol);
        if ($symbol === '') {
            return new WP_Error('invalid_symbol', 'Mã cổ phiếu không hợp lệ.', ['status' => 400]);
        }

        $this->repo->remove_symbol($user_id, $symbol);

        return ['symbol' => $symbol];
    }

    public function get_watchlist($user_id) {
        $admin = $this->get_admin_settings();
        $allowed_fields = $admin['allowed_fields'];

        $user_fields = get_user_meta($user_id, self::META_FIELD_SETTINGS, true);
        if (!is_array($user_fields) || empty($user_fields)) {
            $user_fields = $allowed_fields;
        }

        $fields = array_values(array_intersect($allowed_fields, array_map('sanitize_key', $user_fields)));
        if (empty($fields)) {
            $fields = $allowed_fields;
        }

        return [
            'fields' => $fields,
            'items' => $this->repo->get_watchlist_rows($user_id, $fields),
            'tier' => $this->membership->get_tier($user_id),
        ];
    }

    public function save_user_fields($user_id, $fields) {
        $allowed = $this->get_admin_settings()['allowed_fields'];
        $normalized = array_values(array_intersect($allowed, array_map('sanitize_key', (array) $fields)));
        if (empty($normalized)) {
            $normalized = $allowed;
        }

        update_user_meta($user_id, self::META_FIELD_SETTINGS, $normalized);

        return $normalized;
    }

    public function get_admin_settings() {
        $supported = array_merge(['symbol'], array_keys($this->repo->get_supported_columns()));
        $saved = get_option('lcni_frontend_settings_watchlist', []);

        $allowed_fields = isset($saved['allowed_fields']) && is_array($saved['allowed_fields'])
            ? array_values(array_intersect($supported, array_map('sanitize_key', $saved['allowed_fields'])))
            : ['symbol', 'close_price', 'pct_t_1', 'volume', 'rsi', 'rs_exchange_recommend'];

        if (empty($allowed_fields)) {
            $allowed_fields = ['symbol', 'close_price', 'pct_t_1'];
        }

        return [
            'allowed_fields' => $allowed_fields,
            'supported_fields' => $supported,
        ];
    }

    public function save_admin_settings($payload) {
        $supported = array_merge(['symbol'], array_keys($this->repo->get_supported_columns()));
        $allowed_fields = array_values(array_intersect($supported, array_map('sanitize_key', (array) ($payload['allowed_fields'] ?? []))));

        if (empty($allowed_fields)) {
            return new WP_Error('invalid_allowed_fields', 'Cần chọn tối thiểu 1 cột.', ['status' => 400]);
        }

        $data = ['allowed_fields' => $allowed_fields];
        update_option('lcni_frontend_settings_watchlist', $data, false);

        return $data;
    }

    private function sanitize_symbol($symbol) {
        $symbol = strtoupper(sanitize_text_field((string) $symbol));

        return preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol) ? $symbol : '';
    }
}
