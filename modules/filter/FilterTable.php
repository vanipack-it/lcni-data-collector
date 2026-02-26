<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterTable {
    private $repository;
    private $watchlist_service;

    public function __construct(SnapshotRepository $repository, LCNI_WatchlistService $watchlist_service) {
        $this->repository = $repository;
        $this->watchlist_service = $watchlist_service;
    }

    public function get_settings() {
        $all_columns = $this->watchlist_service->get_all_columns();
        $criteria = $this->normalize_columns(get_option('lcni_filter_criteria_columns', []), $all_columns);
        $table_columns = $this->normalize_columns(get_option('lcni_filter_table_columns', []), $all_columns);
        $table_column_order = $this->normalize_columns(get_option('lcni_filter_table_column_order', []), $all_columns);
        if (empty($criteria)) {
            $criteria = array_slice($all_columns, 0, 8);
        }
        if (empty($table_columns)) {
            $table_columns = $this->watchlist_service->get_default_columns('desktop');
        }
        if (!in_array('symbol', $table_columns, true)) {
            array_unshift($table_columns, 'symbol');
        }
        if (!empty($table_column_order)) {
            $ordered = array_values(array_filter($table_column_order, function ($column) use ($table_columns) {
                return in_array($column, $table_columns, true);
            }));
            foreach ($table_columns as $column) {
                if (!in_array($column, $ordered, true)) {
                    $ordered[] = $column;
                }
            }
            $table_columns = $ordered;
        }

        $style = get_option('lcni_filter_style_config', get_option('lcni_filter_style', []));
        $style = is_array($style) ? $style : [];

        $value_color_rules = get_option('lcni_global_cell_color_rules', []);
        $value_color_rules = is_array($value_color_rules) ? array_values($value_color_rules) : [];

        return [
            'criteria_columns' => $criteria,
            'table_columns' => $table_columns,
            'column_labels' => $this->watchlist_service->get_column_labels($all_columns),
            'style' => [
                'enable_hide_button' => !empty($style['enable_hide_button']),
                'saved_filter_label' => sanitize_text_field((string) ($style['saved_filter_label'] ?? 'Saved Filter')),
                'template_filter_label' => sanitize_text_field((string) ($style['template_filter_label'] ?? 'LCNi Filter Template')),
                'saved_filter_dropdown_bg' => sanitize_hex_color((string) ($style['saved_filter_dropdown_bg'] ?? '#ffffff')) ?: '#ffffff',
                'saved_filter_dropdown_text' => sanitize_hex_color((string) ($style['saved_filter_dropdown_text'] ?? '#111827')) ?: '#111827',
                'saved_filter_dropdown_border' => sanitize_hex_color((string) ($style['saved_filter_dropdown_border'] ?? '#d1d5db')) ?: '#d1d5db',
                'template_filter_dropdown_bg' => sanitize_hex_color((string) ($style['template_filter_dropdown_bg'] ?? '#ffffff')) ?: '#ffffff',
                'template_filter_dropdown_text' => sanitize_hex_color((string) ($style['template_filter_dropdown_text'] ?? '#111827')) ?: '#111827',
                'template_filter_dropdown_border' => sanitize_hex_color((string) ($style['template_filter_dropdown_border'] ?? '#d1d5db')) ?: '#d1d5db',
                'font_size' => max(10, min(24, (int) ($style['font_size'] ?? 13))),
                'text_color' => sanitize_hex_color((string) ($style['text_color'] ?? '#111827')) ?: '#111827',
                'background_color' => sanitize_hex_color((string) ($style['background_color'] ?? '#ffffff')) ?: '#ffffff',
                'row_height' => max(24, min(64, (int) ($style['row_height'] ?? 36))),
                'border_color' => sanitize_hex_color((string) ($style['border_color'] ?? '#e5e7eb')) ?: '#e5e7eb',
                'border_width' => max(0, min(6, (int) ($style['border_width'] ?? 1))),
                'border_radius' => max(0, min(30, (int) ($style['border_radius'] ?? 8))),
                'header_label_font_size' => max(10, min(30, (int) ($style['header_label_font_size'] ?? 12))),
                'row_font_size' => max(10, min(30, (int) ($style['row_font_size'] ?? 13))),
                'panel_label_font_size' => max(10, min(30, (int) ($style['panel_label_font_size'] ?? 13))),
                'panel_value_font_size' => max(10, min(30, (int) ($style['panel_value_font_size'] ?? 13))),
                'panel_label_color' => sanitize_hex_color((string) ($style['panel_label_color'] ?? '#111827')) ?: '#111827',
                'panel_value_color' => sanitize_hex_color((string) ($style['panel_value_color'] ?? '#374151')) ?: '#374151',
                'table_header_font_size' => max(10, min(30, (int) ($style['table_header_font_size'] ?? 12))),
                'table_header_text_color' => sanitize_hex_color((string) ($style['table_header_text_color'] ?? '#111827')) ?: '#111827',
                'table_header_background' => sanitize_hex_color((string) ($style['table_header_background'] ?? '#f3f4f6')) ?: '#f3f4f6',
                'table_value_font_size' => max(10, min(30, (int) ($style['table_value_font_size'] ?? 13))),
                'table_value_text_color' => sanitize_hex_color((string) ($style['table_value_text_color'] ?? '#111827')) ?: '#111827',
                'table_value_background' => sanitize_hex_color((string) ($style['table_value_background'] ?? '#ffffff')) ?: '#ffffff',
                'table_row_divider_color' => sanitize_hex_color((string) ($style['table_row_divider_color'] ?? '#e5e7eb')) ?: '#e5e7eb',
                'table_row_divider_width' => max(0, min(6, (int) ($style['table_row_divider_width'] ?? 1))),
                'sticky_column_count' => max(0, min(5, (int) ($style['sticky_column_count'] ?? 1))),
                'sticky_header_rows' => max(0, min(2, (int) ($style['sticky_header_rows'] ?? 1))),
                'table_header_row_height' => max(28, min(80, (int) ($style['table_header_row_height'] ?? 42))),
                'row_hover_background' => sanitize_hex_color((string) ($style['row_hover_background'] ?? '#eef2ff')) ?: '#eef2ff',
                'conditional_value_colors' => is_string($style['conditional_value_colors'] ?? null) ? (string) $style['conditional_value_colors'] : '[]',
            ],
            'default_filter_values' => $this->get_default_filter_values($criteria),
            'default_saved_filters' => $this->get_effective_default_saved_filters(),
            'value_color_rules' => $value_color_rules,
            'cell_to_cell_rules' => get_option('lcni_cell_to_cell_color_rules', []),
        ];
    }

    public function get_effective_default_saved_filters($user_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'lcni_saved_filters';

        $user_id = (int) $user_id;
        if ($user_id <= 0 && is_user_logged_in()) {
            $user_id = (int) get_current_user_id();
        }

        $filter_id = 0;
        if ($user_id > 0) {
            $filter_id = absint(get_user_meta($user_id, 'lcni_filter_default_saved_filter_id', true));
            if ($filter_id <= 0) {
                $filter_id = absint(get_user_meta($user_id, 'lcni_filter_last_viewed_saved_filter_id', true));
            }
        }
        if ($filter_id <= 0) {
            $filter_id = absint(get_option('lcni_filter_default_admin_saved_filter_id', 0));
        }
        if ($filter_id <= 0) {
            return [];
        }

        $raw = $wpdb->get_var($wpdb->prepare("SELECT filter_config FROM {$table} WHERE id = %d", $filter_id));
        $decoded = json_decode(wp_unslash((string) $raw), true);
        if (!is_array($decoded) || !is_array($decoded['filters'] ?? null)) {
            return [];
        }

        $all_columns = $this->watchlist_service->get_all_columns();
        $criteria = $this->normalize_columns(get_option('lcni_filter_criteria_columns', []), $all_columns);
        if (empty($criteria)) {
            $criteria = array_slice($all_columns, 0, 8);
        }

        return $this->sanitize_filters($decoded['filters'], $criteria);
    }

    public function query($args = []) {
        $settings = $this->get_settings();
        $all_columns = $this->watchlist_service->get_all_columns();
        $requested = isset($args['visible_columns']) && is_array($args['visible_columns']) ? $args['visible_columns'] : $settings['table_columns'];
        $columns = $this->normalize_columns($requested, $all_columns);
        if (empty($columns)) {
            $columns = $settings['table_columns'];
        }
        if (!in_array('symbol', $columns, true)) {
            array_unshift($columns, 'symbol');
        }

        $filters = $this->sanitize_filters(isset($args['filters']) ? $args['filters'] : [], $settings['criteria_columns']);
        $page = max(1, (int) ($args['page'] ?? 1));
        $limit = max(10, min(10000, (int) ($args['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $items = $this->repository->getFiltered($filters, $columns, $limit, $offset);
        $total = $this->repository->countFiltered($filters);

        return [
            'mode' => 'filter',
            'columns' => $columns,
            'column_labels' => $this->watchlist_service->get_column_labels($columns),
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'has_more' => ($offset + count($items)) < $total,
        ];
    }

    public function get_criteria_definitions() {
        $settings = $this->get_settings();
        $defs = [];

        foreach ($settings['criteria_columns'] as $column) {
            $values = $this->repository->getDistinctValues($column);
            $is_number = $this->is_numeric_column($values);

            $definition = [
                'column' => $column,
                'label' => $settings['column_labels'][$column] ?? $column,
                'type' => $is_number ? 'number' : 'text',
            ];

            if ($is_number) {
                $numeric = array_map('floatval', array_filter($values, 'is_numeric'));
                sort($numeric);
                $definition['min'] = (float) ($numeric[0] ?? 0);
                $definition['max'] = (float) ($numeric[count($numeric) - 1] ?? 0);
            } else {
                $definition['values'] = array_slice(array_values(array_unique(array_map('strval', $values))), 0, 120);
            }

            $defs[] = $definition;
        }

        return $defs;
    }

    public function get_default_filter_values(array $criteria_columns) {
        $raw = get_option('lcni_filter_default_values', '');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $column => $value) {
            $column = sanitize_key((string) $column);
            if ($column === '' || !in_array($column, $criteria_columns, true)) {
                continue;
            }

            if (is_array($value)) {
                $normalized[$column] = array_values(array_map(static function ($item) {
                    return sanitize_text_field((string) $item);
                }, $value));
            } else {
                $normalized[$column] = sanitize_text_field((string) $value);
            }
        }

        return $normalized;
    }

    public function sanitize_filters($filters, $allowed_columns) {
        $normalized = [];

        foreach ((array) $filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $column = sanitize_key($filter['column'] ?? '');
            $operator = sanitize_text_field((string) ($filter['operator'] ?? ''));
            $value = $filter['value'] ?? '';

            if ($column === '' || !in_array($column, $allowed_columns, true)) {
                continue;
            }

            if ($operator === 'between') {
                $range = is_array($value) ? array_values($value) : [];
                if (count($range) < 2) {
                    continue;
                }
                $normalized[] = ['column' => $column, 'operator' => 'between', 'value' => [sanitize_text_field((string) $range[0]), sanitize_text_field((string) $range[1])]];
                continue;
            }

            if ($operator === 'in') {
                $items = is_array($value) ? array_filter(array_map('sanitize_text_field', $value)) : [];
                if (empty($items)) {
                    continue;
                }
                $normalized[] = ['column' => $column, 'operator' => 'in', 'value' => array_values($items)];
                continue;
            }

            if (!in_array($operator, ['=', 'contains', '>', '>=', '<', '<='], true)) {
                continue;
            }

            $value = sanitize_text_field((string) $value);
            if ($value === '') {
                continue;
            }

            $normalized[] = ['column' => $column, 'operator' => $operator, 'value' => $value];
        }

        return $normalized;
    }

    public function render_tbody_rows(array $items, array $columns, array $add_button = []): string {
        $html = '';

        foreach ($items as $row) {
            $symbol = isset($row['symbol']) ? (string) $row['symbol'] : '';
            $html .= '<tr data-symbol="' . esc_attr($symbol) . '">';

            foreach ($columns as $index => $column) {
                $sticky = $index === 0 ? ' class="is-sticky-col"' : '';

                if ($column === 'symbol') {
                    $html .= '<td' . $sticky . '><span>' . esc_html($symbol) . '</span> '
                        . '<button type="button" class="lcni-btn lcni-btn-btn_add_filter_row" data-lcni-watchlist-add data-symbol="' . esc_attr($symbol) . '" aria-label="Add to watchlist">'
                        . LCNI_Button_Style_Config::build_button_content('btn_add_filter_row', '') . '</button></td>';
                    continue;
                }

                $value = isset($row[$column]) ? (string) $row[$column] : '';
                $html .= '<td' . $sticky . '>' . esc_html($value) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html;
    }

    private function normalize_columns($columns, $all_columns) {
        $columns = is_array($columns) ? array_map('sanitize_key', $columns) : [];

        return array_values(array_intersect($all_columns, $columns));
    }

    private function is_numeric_column($values) {
        $sample = array_slice(array_values(array_filter((array) $values, static function ($v) {
            return $v !== null && $v !== '';
        })), 0, 20);
        if (empty($sample)) {
            return false;
        }

        foreach ($sample as $value) {
            if (!is_numeric($value)) {
                return false;
            }
        }

        return true;
    }
}
