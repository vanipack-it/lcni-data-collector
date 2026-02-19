<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistService {

    const USER_SETTINGS_META_KEY = 'lcni_watchlist_columns';
    const CACHE_GROUP = 'lcni_watchlist';
    const CACHE_TTL = 120;

    private $repository;
    private $default_columns = ['symbol', 'close_price', 'pct_t_1', 'volume', 'exchange'];

    public function __construct(LCNI_WatchlistRepository $repository) {
        $this->repository = $repository;
    }

    public function add_symbol($user_id, $symbol) {
        $symbol = $this->sanitize_symbol($symbol);
        if ($symbol === '') {
            return new WP_Error('invalid_symbol', 'Symbol không hợp lệ.', ['status' => 400]);
        }

        $this->repository->add($user_id, $symbol);
        $this->clear_user_cache($user_id);

        return ['symbol' => $symbol, 'success' => true];
    }

    public function remove_symbol($user_id, $symbol) {
        $symbol = $this->sanitize_symbol($symbol);
        if ($symbol === '') {
            return new WP_Error('invalid_symbol', 'Symbol không hợp lệ.', ['status' => 400]);
        }

        $this->repository->remove($user_id, $symbol);
        $this->clear_user_cache($user_id);

        return ['symbol' => $symbol, 'success' => true];
    }

    public function get_watchlist($user_id, $columns) {
        $allowed_columns = $this->get_allowed_columns();
        $effective_columns = array_values(array_intersect($allowed_columns, $columns));
        if (empty($effective_columns)) {
            $effective_columns = $this->default_columns;
        }

        $cache_key = 'watchlist:' . $user_id . ':' . $this->get_cache_version($user_id) . ':' . md5(wp_json_encode($effective_columns));
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return [
                'columns' => $effective_columns,
                'items' => $cached,
            ];
        }

        $rows = $this->repository->get_by_user($user_id, $effective_columns);
        wp_cache_set($cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL);

        return [
            'columns' => $effective_columns,
            'items' => $rows,
        ];
    }

    public function get_user_columns($user_id) {
        $allowed_columns = $this->get_allowed_columns();
        $saved = get_user_meta($user_id, self::USER_SETTINGS_META_KEY, true);

        if (!is_array($saved) || empty($saved)) {
            return $this->default_columns;
        }

        $columns = array_values(array_intersect($allowed_columns, array_map('sanitize_key', $saved)));

        return !empty($columns) ? $columns : $this->default_columns;
    }

    public function save_user_columns($user_id, $columns) {
        $allowed_columns = $this->get_allowed_columns();
        $normalized = array_values(array_intersect($allowed_columns, array_map('sanitize_key', (array) $columns)));

        if (empty($normalized)) {
            $normalized = $this->default_columns;
        }

        update_user_meta($user_id, self::USER_SETTINGS_META_KEY, $normalized);
        $this->clear_user_cache($user_id);

        return $normalized;
    }

    public function get_allowed_columns() {
        $settings = get_option('lcni_watchlist_settings', []);
        $allowed = isset($settings['allowed_columns']) && is_array($settings['allowed_columns'])
            ? array_map('sanitize_key', $settings['allowed_columns'])
            : $this->get_all_columns();

        $normalized = array_values(array_intersect($this->get_all_columns(), $allowed));

        return !empty($normalized) ? $normalized : $this->default_columns;
    }

    public function get_all_columns() {
        return ['symbol', 'close_price', 'pct_t_1', 'volume', 'value_traded', 'exchange', 'market_id', 'eps', 'roe', 'pe_ratio', 'pb_ratio', 'tc_rating', 'xep_hang', 'created_at'];
    }

    private function sanitize_symbol($symbol) {
        return strtoupper(sanitize_text_field((string) $symbol));
    }

    private function clear_user_cache($user_id) {
        $version_key = 'watchlist_version_' . $user_id;
        $version = (int) get_transient($version_key);
        set_transient($version_key, $version + 1, DAY_IN_SECONDS);
    }

    private function get_cache_version($user_id) {
        return (int) get_transient('watchlist_version_' . $user_id);
    }
}
