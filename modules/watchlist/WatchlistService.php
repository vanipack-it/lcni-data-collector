<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_WatchlistService {

    const USER_SETTINGS_META_KEY = 'lcni_watchlist_columns';
    const USER_SYMBOLS_META_KEY = 'lcni_watchlist_symbols';
    const CACHE_GROUP = 'lcni_watchlist';
    const CACHE_TTL = 120;

    private $repository;
    private $preferred_default_columns = ['symbol', 'close_price', 'pct_t_1', 'volume', 'value_traded', 'exchange'];

    public function __construct(LCNI_WatchlistRepository $repository) {
        $this->repository = $repository;
    }


    public function get_settings() {
        $settings = get_option('lcni_watchlist_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $styles = isset($settings['styles']) && is_array($settings['styles']) ? $settings['styles'] : [];
        $rules = isset($settings['value_color_rules']) && is_array($settings['value_color_rules']) ? $settings['value_color_rules'] : [];

        return [
            'allowed_columns' => $this->get_allowed_columns(),
            'default_columns_desktop' => $this->get_default_columns('desktop'),
            'default_columns_mobile' => $this->get_default_columns('mobile'),
            'column_labels' => $this->get_column_labels($this->get_allowed_columns()),
            'styles' => [
                'label_font_size' => max(10, min(30, (int) ($styles['label_font_size'] ?? 12))),
                'row_font_size' => max(10, min(30, (int) ($styles['row_font_size'] ?? 13))),
            ],
            'value_color_rules' => array_values(array_filter(array_map(static function ($rule) {
                if (!is_array($rule)) {
                    return null;
                }

                $column = sanitize_key($rule['column'] ?? '');
                $operator = sanitize_text_field((string) ($rule['operator'] ?? ''));
                $value = $rule['value'] ?? '';
                $bg_color = sanitize_hex_color((string) ($rule['bg_color'] ?? ''));
                $text_color = sanitize_hex_color((string) ($rule['text_color'] ?? ''));

                if ($column === '' || !in_array($operator, ['>', '>=', '<', '<=', '=', '!='], true) || !$bg_color || !$text_color || $value === '') {
                    return null;
                }

                return [
                    'column' => $column,
                    'operator' => $operator,
                    'value' => $value,
                    'bg_color' => $bg_color,
                    'text_color' => $text_color,
                ];
            }, $rules))),
        ];
    }

    public function add_symbol($user_id, $symbol) {
        $symbol = $this->sanitize_symbol($symbol);
        if ($symbol === '') {
            return new WP_Error('invalid_symbol', 'Symbol không hợp lệ.', ['status' => 400]);
        }

        $symbols = $this->get_user_symbols($user_id);
        if (in_array($symbol, $symbols, true)) {
            return ['symbol' => $symbol, 'success' => true, 'already_exists' => true, 'symbols' => $symbols];
        }

        array_unshift($symbols, $symbol);
        $this->save_user_symbols($user_id, $symbols);

        return ['symbol' => $symbol, 'success' => true, 'already_exists' => false, 'symbols' => $symbols];
    }

    public function remove_symbol($user_id, $symbol) {
        $symbol = $this->sanitize_symbol($symbol);
        if ($symbol === '') {
            return new WP_Error('invalid_symbol', 'Symbol không hợp lệ.', ['status' => 400]);
        }

        $symbols = $this->get_user_symbols($user_id);
        $next_symbols = array_values(array_filter($symbols, static function ($item) use ($symbol) {
            return $item !== $symbol;
        }));

        $this->save_user_symbols($user_id, $next_symbols);

        return ['symbol' => $symbol, 'success' => true, 'symbols' => $next_symbols];
    }

    public function get_watchlist($user_id, $columns, $device = 'desktop') {
        $allowed_columns = $this->get_allowed_columns();
        $effective_columns = array_values(array_intersect($allowed_columns, $columns));
        if (empty($effective_columns)) {
            $effective_columns = $this->get_default_columns($device);
        }

        $symbols = $this->get_user_symbols($user_id);
        if (empty($symbols)) {
            return [
                'columns' => $effective_columns,
                'items' => [],
                'symbols' => [],
                'column_labels' => $this->get_column_labels($effective_columns),
            ];
        }

        $cache_key = 'watchlist:' . $user_id . ':' . $this->get_cache_version($user_id) . ':' . md5(wp_json_encode([$effective_columns, $symbols]));
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return [
                'columns' => $effective_columns,
                'items' => $cached,
                'symbols' => $symbols,
                'column_labels' => $this->get_column_labels($effective_columns),
            ];
        }

        $rows = $this->repository->get_by_symbols($symbols, $effective_columns);
        wp_cache_set($cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL);

        return [
            'columns' => $effective_columns,
            'items' => $rows,
            'symbols' => $symbols,
            'column_labels' => $this->get_column_labels($effective_columns),
        ];
    }

    public function get_user_columns($user_id, $device = 'desktop') {
        $allowed_columns = $this->get_allowed_columns();
        $saved = get_user_meta($user_id, self::USER_SETTINGS_META_KEY, true);

        if (is_string($saved) && $saved !== '') {
            $decoded = json_decode($saved, true);
            if (is_array($decoded)) {
                $saved = isset($decoded['columns']) && is_array($decoded['columns']) ? $decoded['columns'] : $decoded;
            }
        }

        if (!is_array($saved) || empty($saved)) {
            return $this->get_default_columns($device);
        }

        $columns = array_values(array_intersect($allowed_columns, array_map('sanitize_key', $saved)));

        return !empty($columns) ? $columns : $this->get_default_columns($device);
    }

    public function save_user_columns($user_id, $columns) {
        $allowed_columns = $this->get_allowed_columns();
        $normalized = array_values(array_intersect($allowed_columns, array_map('sanitize_key', (array) $columns)));

        if (empty($normalized)) {
            $normalized = $this->get_default_columns();
        }

        update_user_meta($user_id, self::USER_SETTINGS_META_KEY, wp_json_encode([
            'columns' => $normalized,
            'updated_at' => current_time('mysql'),
        ]));
        $this->clear_user_cache($user_id);

        return $normalized;
    }

    public function get_user_symbols($user_id) {
        $saved = get_user_meta($user_id, self::USER_SYMBOLS_META_KEY, true);
        if (is_string($saved) && $saved !== '') {
            $decoded = json_decode($saved, true);
            if (is_array($decoded)) {
                $saved = $decoded;
            }
        }

        if (!is_array($saved)) {
            return [];
        }

        $symbols = array_values(array_unique(array_filter(array_map([$this, 'sanitize_symbol'], $saved))));

        return $symbols;
    }

    public function get_allowed_columns() {
        $settings = get_option('lcni_watchlist_settings', []);
        $allowed = isset($settings['allowed_columns']) && is_array($settings['allowed_columns'])
            ? array_map('sanitize_key', $settings['allowed_columns'])
            : $this->get_all_columns();

        $normalized = array_values(array_intersect($this->get_all_columns(), $allowed));

        return !empty($normalized) ? $normalized : $this->get_default_columns();
    }

    public function get_column_labels($columns) {
        $settings = get_option('lcni_watchlist_settings', []);
        $configured = isset($settings['column_labels']) && is_array($settings['column_labels']) ? $settings['column_labels'] : [];
        $label_map = [];

        foreach ($configured as $item) {
            if (!is_array($item)) {
                continue;
            }
            $data_key = sanitize_key($item['data_key'] ?? '');
            if ($data_key === '') {
                continue;
            }
            $label = sanitize_text_field((string) ($item['label'] ?? ''));
            if ($label !== '') {
                $label_map[$data_key] = $label;
            }
        }

        $result = [];
        foreach ((array) $columns as $column) {
            $result[$column] = $label_map[$column] ?? $column;
        }

        return $result;
    }

    public function get_all_columns() {
        return $this->repository->get_available_columns();
    }

    public function get_default_columns($device = 'desktop') {
        $settings = get_option('lcni_watchlist_settings', []);
        $allowed_columns = $this->get_allowed_columns();
        $key = $device === 'mobile' ? 'default_columns_mobile' : 'default_columns_desktop';
        $configured = isset($settings[$key]) && is_array($settings[$key])
            ? array_values(array_intersect($allowed_columns, array_map('sanitize_key', $settings[$key])))
            : [];

        if (!empty($configured)) {
            return $configured;
        }

        $all_columns = $this->get_all_columns();
        $defaults = array_values(array_intersect($all_columns, $this->preferred_default_columns));

        if (!empty($defaults)) {
            return $defaults;
        }

        $fallback = array_slice($all_columns, 0, 6);

        if ($device === 'mobile') {
            return array_slice($fallback, 0, 4);
        }

        return $fallback;
    }

    private function sanitize_symbol($symbol) {
        $symbol = strtoupper(trim(sanitize_text_field((string) $symbol)));

        return preg_match('/^[A-Z0-9._-]+$/', $symbol) ? $symbol : '';
    }

    private function save_user_symbols($user_id, $symbols) {
        update_user_meta($user_id, self::USER_SYMBOLS_META_KEY, wp_json_encode(array_values($symbols)));
        $this->clear_user_cache($user_id);
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
