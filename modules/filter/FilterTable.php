<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_FilterTable {
    private $repository;
    private $watchlist_service;

    public function __construct(LCNI_WatchlistRepository $repository, LCNI_WatchlistService $watchlist_service) {
        $this->repository = $repository;
        $this->watchlist_service = $watchlist_service;
    }

    public function get_settings() {
        $watchlist_settings = $this->watchlist_service->get_settings();
        $watchlist_allowed_columns = $this->watchlist_service->get_allowed_columns();
        $saved_columns = get_option('lcni_filter_allowed_columns', []);
        $saved_columns = is_array($saved_columns) ? array_map('sanitize_key', $saved_columns) : [];

        $allowed_columns = array_values(array_intersect($watchlist_allowed_columns, $saved_columns));
        if (empty($allowed_columns)) {
            $allowed_columns = $watchlist_allowed_columns;
        }

        return [
            'allowed_columns' => $allowed_columns,
            'column_labels' => $this->watchlist_service->get_column_labels($allowed_columns),
            'styles' => $watchlist_settings['styles'] ?? [],
            'value_color_rules' => $watchlist_settings['value_color_rules'] ?? [],
            'default_conditions' => $this->get_default_conditions($allowed_columns),
            'add_button' => $this->get_add_button_styles(),
        ];
    }

    public function query($args = []) {
        $settings = $this->get_settings();
        $allowed_columns = $settings['allowed_columns'];
        $columns = isset($args['columns']) && is_array($args['columns']) ? array_values(array_intersect($allowed_columns, array_map('sanitize_key', $args['columns']))) : [];
        if (empty($columns)) {
            $columns = array_slice($allowed_columns, 0, 8);
        }
        if (!in_array('symbol', $columns, true)) {
            array_unshift($columns, 'symbol');
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $limit = max(10, min(200, (int) ($args['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $filters = $this->sanitize_filters(isset($args['filters']) && is_array($args['filters']) ? $args['filters'] : [], $allowed_columns);

        $items = $this->repository->get_all($columns, $limit, $offset, $filters);
        $total = $this->repository->count_all($filters);

        return [
            'mode' => 'all_symbols',
            'columns' => $columns,
            'column_labels' => $this->watchlist_service->get_column_labels($columns),
            'items' => $items,
            'filters' => $filters,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'has_more' => ($offset + count($items)) < $total,
        ];
    }

    public function sanitize_filters($filters, $allowed_columns) {
        $normalized = [];
        $allowed_operators = ['=', '>', '<', '>=', '<=', 'between', 'contains'];

        foreach ((array) $filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $column = sanitize_key($filter['column'] ?? '');
            $operator = sanitize_text_field((string) ($filter['operator'] ?? ''));
            $value = $filter['value'] ?? '';

            if ($column === '' || !in_array($column, $allowed_columns, true) || !in_array($operator, $allowed_operators, true)) {
                continue;
            }

            if ($operator === 'between') {
                $range = is_array($value) ? array_values($value) : preg_split('/\s*,\s*/', (string) $value);
                if (count($range) < 2) {
                    continue;
                }
                $normalized[] = ['column' => $column, 'operator' => $operator, 'value' => [sanitize_text_field((string) $range[0]), sanitize_text_field((string) $range[1])]];
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

    private function get_default_conditions($allowed_columns) {
        $conditions = get_option('lcni_filter_default_conditions', []);

        return $this->sanitize_filters(is_array($conditions) ? $conditions : [], $allowed_columns);
    }

    private function get_add_button_styles() {
        $watchlist_settings = get_option('lcni_watchlist_settings', []);
        $button = isset($watchlist_settings['add_button']) && is_array($watchlist_settings['add_button']) ? $watchlist_settings['add_button'] : [];

        return [
            'icon' => sanitize_text_field((string) ($button['icon'] ?? 'fa-solid fa-heart-circle-plus')),
            'background' => sanitize_hex_color((string) ($button['background'] ?? '#dc2626')) ?: '#dc2626',
            'text_color' => sanitize_hex_color((string) ($button['text_color'] ?? '#ffffff')) ?: '#ffffff',
            'font_size' => max(10, min(24, (int) ($button['font_size'] ?? 14))),
            'size' => max(20, min(48, (int) ($button['size'] ?? 26))),
        ];
    }
}
