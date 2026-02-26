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
    private $wpdb;
    private $watchlists_table;
    private $watchlist_symbols_table;
    private $active_watchlist_meta_key = 'lcni_active_watchlist_id';
    private $preferred_default_columns = ['symbol', 'close_price', 'pct_t_1', 'volume', 'value_traded', 'exchange'];

    public function __construct(LCNI_WatchlistRepository $repository) {
        global $wpdb;
        $this->repository = $repository;
        $this->wpdb = $wpdb;
        $this->watchlists_table = $this->wpdb->prefix . 'lcni_watchlists';
        $this->watchlist_symbols_table = $this->wpdb->prefix . 'lcni_watchlist_symbols';
    }

    public function list_watchlists($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return [];
        }

        $this->ensure_default_watchlist($user_id);
        $sql = $this->wpdb->prepare("SELECT id, name, is_default FROM {$this->watchlists_table} WHERE user_id = %d ORDER BY is_default DESC, id ASC", $user_id);
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? array_map(static function ($row) {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => sanitize_text_field((string) ($row['name'] ?? '')),
                'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            ];
        }, $rows) : [];
    }

    public function create_watchlist($user_id, $name) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', 'User không hợp lệ.', ['status' => 403]);
        }

        $name = sanitize_text_field((string) $name);
        if ($name === '') {
            $name = 'Watchlist ' . gmdate('Y-m-d H:i');
        }

        $this->wpdb->insert($this->watchlists_table, [
            'user_id' => $user_id,
            'name' => $name,
            'is_default' => 0,
        ], ['%d', '%s', '%d']);

        $id = (int) $this->wpdb->insert_id;
        if ($id <= 0) {
            return new WP_Error('create_failed', 'Không thể tạo watchlist.', ['status' => 500]);
        }

        update_user_meta($user_id, $this->active_watchlist_meta_key, $id);

        return ['id' => $id, 'name' => $name];
    }

    public function delete_watchlist($user_id, $watchlist_id) {
        $user_id = absint($user_id);
        $watchlist_id = absint($watchlist_id);
        if ($user_id <= 0 || $watchlist_id <= 0) {
            return new WP_Error('invalid_request', 'Dữ liệu không hợp lệ.', ['status' => 400]);
        }

        $watchlist = $this->get_user_watchlist($user_id, $watchlist_id);
        if (!$watchlist) {
            return new WP_Error('not_found', 'Watchlist không tồn tại.', ['status' => 404]);
        }

        $this->wpdb->delete($this->watchlist_symbols_table, ['watchlist_id' => $watchlist_id], ['%d']);
        $this->wpdb->delete($this->watchlists_table, ['id' => $watchlist_id, 'user_id' => $user_id], ['%d', '%d']);

        $fallback = $this->ensure_default_watchlist($user_id);
        update_user_meta($user_id, $this->active_watchlist_meta_key, $fallback);
        $this->clear_user_cache($user_id);

        return ['deleted' => true, 'active_watchlist_id' => $fallback];
    }

    public function get_active_watchlist_id($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return 0;
        }

        $default_id = $this->ensure_default_watchlist($user_id);
        $active_id = absint(get_user_meta($user_id, $this->active_watchlist_meta_key, true));
        if ($active_id <= 0 || !$this->get_user_watchlist($user_id, $active_id)) {
            $active_id = $default_id;
            update_user_meta($user_id, $this->active_watchlist_meta_key, $active_id);
        }

        return $active_id;
    }

    public function set_active_watchlist($user_id, $watchlist_id) {
        $watchlist_id = absint($watchlist_id);
        if ($watchlist_id <= 0 || !$this->get_user_watchlist($user_id, $watchlist_id)) {
            return new WP_Error('invalid_watchlist', 'Watchlist không hợp lệ.', ['status' => 400]);
        }

        update_user_meta($user_id, $this->active_watchlist_meta_key, $watchlist_id);

        return ['active_watchlist_id' => $watchlist_id];
    }

    public function add_symbol($user_id, $symbol, $watchlist_id = 0) {
        $user_id = absint($user_id);
        $symbol = $this->sanitize_symbol($symbol);
        if ($user_id <= 0 || $symbol === '') {
            return new WP_Error('invalid_symbol', 'Symbol không hợp lệ.', ['status' => 400]);
        }

        $target_watchlist_id = $watchlist_id > 0 ? absint($watchlist_id) : $this->get_active_watchlist_id($user_id);
        if (!$this->get_user_watchlist($user_id, $target_watchlist_id)) {
            return new WP_Error('invalid_watchlist', 'Watchlist không hợp lệ.', ['status' => 400]);
        }

        $watchlist = $this->get_user_watchlist($user_id, $target_watchlist_id);
        $watchlist_name = sanitize_text_field((string) ($watchlist['name'] ?? ''));
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->watchlist_symbols_table} WHERE watchlist_id = %d AND symbol = %s",
            $target_watchlist_id,
            $symbol
        ));

        if ($existing) {
            return new WP_Error('duplicate_symbol', sprintf('Mã %s đã có trong watchlist: %s.', $symbol, $watchlist_name !== '' ? $watchlist_name : ('#' . $target_watchlist_id)), [
                'status' => 409,
                'symbol' => $symbol,
                'watchlist_id' => $target_watchlist_id,
                'watchlist_name' => $watchlist_name,
            ]);
        }

        $this->wpdb->insert($this->watchlist_symbols_table, [
            'watchlist_id' => $target_watchlist_id,
            'symbol' => $symbol,
        ], ['%d', '%s']);
        $this->clear_user_cache($user_id);

        $symbols = $this->get_watchlist_symbols($target_watchlist_id);

        return ['symbol' => $symbol, 'success' => true, 'watchlist_id' => $target_watchlist_id, 'watchlist_name' => $watchlist_name, 'symbols' => $symbols];
    }

    public function remove_symbol($user_id, $symbol, $watchlist_id = 0) {
        $user_id = absint($user_id);
        $symbol = $this->sanitize_symbol($symbol);
        if ($user_id <= 0 || $symbol === '') {
            return new WP_Error('invalid_symbol', 'Symbol không hợp lệ.', ['status' => 400]);
        }

        $target_watchlist_id = $watchlist_id > 0 ? absint($watchlist_id) : $this->get_active_watchlist_id($user_id);
        if (!$this->get_user_watchlist($user_id, $target_watchlist_id)) {
            return new WP_Error('invalid_watchlist', 'Watchlist không hợp lệ.', ['status' => 400]);
        }

        $this->wpdb->delete($this->watchlist_symbols_table, ['watchlist_id' => $target_watchlist_id, 'symbol' => $symbol], ['%d', '%s']);
        $this->clear_user_cache($user_id);

        return ['symbol' => $symbol, 'success' => true, 'watchlist_id' => $target_watchlist_id, 'symbols' => $this->get_watchlist_symbols($target_watchlist_id)];
    }

    public function get_watchlist($user_id, $columns, $device = 'desktop', $watchlist_id = 0) {
        $allowed_columns = $this->get_allowed_columns();
        $requested_columns = is_array($columns) ? array_map('sanitize_key', $columns) : [];
        $effective_columns = array_values(array_filter($requested_columns, static function ($column) use ($allowed_columns) {
            return in_array($column, $allowed_columns, true);
        }));
        if (empty($effective_columns)) {
            $effective_columns = $this->get_default_columns($device);
        }
        $query_columns = $this->append_rule_dependency_columns($effective_columns);

        $watchlist_id = $watchlist_id > 0 ? absint($watchlist_id) : $this->get_active_watchlist_id($user_id);
        $symbols = $this->get_watchlist_symbols($watchlist_id);

        if (empty($symbols)) {
            return [
                'columns' => $effective_columns,
                'items' => [],
                'symbols' => [],
                'column_labels' => $this->get_column_labels($effective_columns),
                'watchlist_id' => $watchlist_id,
            ];
        }

        $cache_key = 'watchlist:' . $user_id . ':' . $watchlist_id . ':' . $this->get_cache_version($user_id) . ':' . md5(wp_json_encode([$query_columns, $symbols]));
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return [
                'columns' => $effective_columns,
                'items' => $cached,
                'symbols' => $symbols,
                'column_labels' => $this->get_column_labels($effective_columns),
                'watchlist_id' => $watchlist_id,
            ];
        }

        $rows = $this->repository->get_by_symbols($symbols, $query_columns);
        wp_cache_set($cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL);

        return [
            'columns' => $effective_columns,
            'items' => $rows,
            'symbols' => $symbols,
            'column_labels' => $this->get_column_labels($effective_columns),
            'watchlist_id' => $watchlist_id,
        ];
    }

    public function append_rule_dependency_columns(array $columns): array {
        $base_columns = array_values(array_unique(array_map('sanitize_key', $columns)));
        $all_columns = $this->get_all_columns();
        $source_fields = [];
        $rules = get_option('lcni_cell_to_cell_color_rules', []);

        foreach ((array) $rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $field = sanitize_key((string) ($rule['source_field'] ?? ''));
            if ($field !== '' && in_array($field, $all_columns, true)) {
                $source_fields[] = $field;
            }
        }

        return array_values(array_unique(array_merge($base_columns, $source_fields)));
    }

    private function get_watchlist_symbols($watchlist_id) {
        $watchlist_id = absint($watchlist_id);
        if ($watchlist_id <= 0) {
            return [];
        }

        $sql = $this->wpdb->prepare("SELECT symbol FROM {$this->watchlist_symbols_table} WHERE watchlist_id = %d ORDER BY id DESC", $watchlist_id);
        $symbols = $this->wpdb->get_col($sql);

        return is_array($symbols) ? array_values(array_unique(array_filter(array_map([$this, 'sanitize_symbol'], $symbols)))) : [];
    }

    private function get_user_watchlist($user_id, $watchlist_id) {
        $sql = $this->wpdb->prepare("SELECT id, user_id, name, is_default FROM {$this->watchlists_table} WHERE id = %d AND user_id = %d", absint($watchlist_id), absint($user_id));
        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    private function ensure_default_watchlist($user_id) {
        $user_id = absint($user_id);
        $existing = $this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->watchlists_table} WHERE user_id = %d ORDER BY is_default DESC, id ASC LIMIT 1", $user_id));
        if ($existing) {
            return (int) $existing;
        }

        $this->wpdb->insert($this->watchlists_table, [
            'user_id' => $user_id,
            'name' => 'Default Watchlist',
            'is_default' => 1,
        ], ['%d', '%s', '%d']);

        return (int) $this->wpdb->insert_id;
    }

    public function get_settings() {
        $settings = get_option('lcni_watchlist_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $styles = isset($settings['styles']) && is_array($settings['styles']) ? $settings['styles'] : [];
        $rules = isset($settings['value_color_rules']) && is_array($settings['value_color_rules']) ? $settings['value_color_rules'] : [];
        $global_value_rules = get_option('lcni_global_cell_color_rules', []);
        if (is_array($global_value_rules) && !empty($global_value_rules)) {
            $rules = array_merge($rules, $global_value_rules);
        }
        $cell_to_cell_rules = get_option('lcni_cell_to_cell_color_rules', []);
        $cell_to_cell_rules = is_array($cell_to_cell_rules) ? $cell_to_cell_rules : [];

        return [
            'allowed_columns' => $this->get_allowed_columns(),
            'default_columns_desktop' => $this->get_default_columns('desktop'),
            'default_columns_mobile' => $this->get_default_columns('mobile'),
            'column_labels' => $this->get_column_labels($this->get_allowed_columns()),
            'styles' => [
                'label_font_size' => max(10, min(30, (int) ($styles['label_font_size'] ?? 12))),
                'row_font_size' => max(10, min(30, (int) ($styles['row_font_size'] ?? 13))),
                'header_background' => sanitize_hex_color($styles['header_background'] ?? '#ffffff') ?: '#ffffff',
                'header_text_color' => sanitize_hex_color($styles['header_text_color'] ?? '#111827') ?: '#111827',
                'value_background' => sanitize_hex_color($styles['value_background'] ?? '#ffffff') ?: '#ffffff',
                'value_text_color' => sanitize_hex_color($styles['value_text_color'] ?? '#111827') ?: '#111827',
                'row_divider_color' => sanitize_hex_color($styles['row_divider_color'] ?? '#e5e7eb') ?: '#e5e7eb',
                'row_divider_width' => max(1, min(6, (int) ($styles['row_divider_width'] ?? 1))),
                'row_hover_bg' => sanitize_hex_color($styles['row_hover_bg'] ?? '#f3f4f6') ?: '#f3f4f6',
                'head_height' => max(1, min(240, (int) ($styles['head_height'] ?? 40))),
                'sticky_column' => sanitize_key($styles['sticky_column'] ?? 'symbol'),
                'sticky_header' => !empty($styles['sticky_header']) ? 1 : 0,
                'dropdown_height' => max(28, min(60, (int) ($styles['dropdown_height'] ?? 34))),
                'dropdown_font_size' => max(10, min(24, (int) ($styles['dropdown_font_size'] ?? 13))),
                'dropdown_border_color' => sanitize_hex_color($styles['dropdown_border_color'] ?? '#d1d5db') ?: '#d1d5db',
                'dropdown_border_radius' => max(0, min(24, (int) ($styles['dropdown_border_radius'] ?? 8))),
                'input_height' => max(28, min(60, (int) ($styles['input_height'] ?? 34))),
                'input_font_size' => max(10, min(24, (int) ($styles['input_font_size'] ?? 13))),
                'input_border_color' => sanitize_hex_color($styles['input_border_color'] ?? '#d1d5db') ?: '#d1d5db',
                'input_border_radius' => max(0, min(24, (int) ($styles['input_border_radius'] ?? 8))),
                'scroll_speed' => max(1, min(5, (int) ($styles['scroll_speed'] ?? 1))),
                'column_order' => array_values(array_map('sanitize_key', is_array($styles['column_order'] ?? null) ? $styles['column_order'] : [])),
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
                if ($column === '' || !in_array($operator, ['>', '>=', '<', '<=', '=', '!=', 'contains', 'not_contains'], true) || !$bg_color || !$text_color || $value === '') {
                    return null;
                }
                return ['column' => $column, 'operator' => $operator, 'value' => $value, 'bg_color' => $bg_color, 'text_color' => $text_color, 'icon_class' => sanitize_text_field((string) ($rule['icon_class'] ?? '')), 'icon_position' => in_array(($rule['icon_position'] ?? 'left'), ['left', 'right'], true) ? $rule['icon_position'] : 'left'];
            }, $rules))),
            'cell_to_cell_rules' => array_values(array_filter(array_map(static function ($rule) {
                if (!is_array($rule)) {
                    return null;
                }
                $source_field = sanitize_key($rule['source_field'] ?? '');
                $target_field = sanitize_key($rule['target_field'] ?? '');
                $operator = sanitize_text_field((string) ($rule['operator'] ?? ''));
                $value = $rule['value'] ?? '';
                if ($source_field === '' || $target_field === '' || !in_array($operator, ['=', '!=', '>', '>=', '<', '<=', 'contains', 'not_contains'], true) || $value === '') {
                    return null;
                }
                return [
                    'source_field' => $source_field,
                    'target_field' => $target_field,
                    'operator' => $operator,
                    'value' => $value,
                    'text_color' => sanitize_hex_color((string) ($rule['text_color'] ?? '')) ?: '#111827',
                    'icon_class' => sanitize_text_field((string) ($rule['icon_class'] ?? '')),
                    'icon_position' => in_array(($rule['icon_position'] ?? 'right'), ['left', 'right'], true) ? $rule['icon_position'] : 'right',
                    'icon_size' => max(8, min(32, (int) ($rule['icon_size'] ?? 12))),
                    'icon_color' => sanitize_hex_color((string) ($rule['icon_color'] ?? '')) ?: '#dc2626',
                ];
            }, $cell_to_cell_rules))),
        ];
    }

    public function get_user_columns($user_id, $device = 'desktop') { /* unchanged */
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
        update_user_meta($user_id, self::USER_SETTINGS_META_KEY, wp_json_encode(['columns' => $normalized, 'updated_at' => current_time('mysql')]));
        $this->clear_user_cache($user_id);
        return $normalized;
    }

    public function get_allowed_columns() {
        $settings = get_option('lcni_watchlist_settings', []);
        $allowed = isset($settings['allowed_columns']) && is_array($settings['allowed_columns']) ? array_map('sanitize_key', $settings['allowed_columns']) : $this->get_all_columns();
        $normalized = array_values(array_intersect($this->get_all_columns(), $allowed));
        return !empty($normalized) ? $normalized : $this->get_default_columns();
    }

    public function get_column_labels($columns) {
        $global = get_option('lcni_column_labels', []);
        $configured = is_array($global) ? $global : [];
        if (empty($configured)) {
            $settings = get_option('lcni_watchlist_settings', []);
            $configured = isset($settings['column_labels']) && is_array($settings['column_labels']) ? $settings['column_labels'] : [];
        }
        $label_map = [];
        foreach ($configured as $key => $item) {
            if (is_array($item)) {
                $data_key = sanitize_key($item['data_key'] ?? '');
                $label = sanitize_text_field((string) ($item['label'] ?? ''));
            } else {
                $data_key = sanitize_key($key);
                $label = sanitize_text_field((string) $item);
            }
            if ($data_key !== '' && $label !== '') {
                $label_map[$data_key] = $label;
            }
        }
        $result = [];
        foreach ((array) $columns as $column) {
            $result[$column] = $label_map[$column] ?? $column;
        }
        return $result;
    }

    public function get_all_columns() { return $this->repository->get_available_columns(); }

    public function get_default_columns($device = 'desktop') {
        $settings = get_option('lcni_watchlist_settings', []);
        $allowed_columns = $this->get_allowed_columns();
        $key = $device === 'mobile' ? 'default_columns_mobile' : 'default_columns_desktop';
        $configured = isset($settings[$key]) && is_array($settings[$key]) ? array_values(array_intersect($allowed_columns, array_map('sanitize_key', $settings[$key]))) : [];
        if (!empty($configured)) return $configured;
        $all_columns = $this->get_all_columns();
        $defaults = array_values(array_intersect($all_columns, $this->preferred_default_columns));
        if (!empty($defaults)) return $defaults;
        $fallback = array_slice($all_columns, 0, 6);
        return $device === 'mobile' ? array_slice($fallback, 0, 4) : $fallback;
    }

    private function sanitize_symbol($symbol) {
        $symbol = strtoupper(trim(sanitize_text_field((string) $symbol)));
        return preg_match('/^[A-Z0-9._-]+$/', $symbol) ? $symbol : '';
    }

    private function clear_user_cache($user_id) {
        $version_key = 'watchlist_version_' . absint($user_id);
        $version = (int) get_transient($version_key);
        set_transient($version_key, $version + 1, DAY_IN_SECONDS);
    }

    private function get_cache_version($user_id) {
        return (int) get_transient('watchlist_version_' . absint($user_id));
    }
}
