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
        if (empty($criteria)) {
            $criteria = array_slice($all_columns, 0, 8);
        }
        if (empty($table_columns)) {
            $table_columns = $this->watchlist_service->get_default_columns('desktop');
        }
        if (!in_array('symbol', $table_columns, true)) {
            array_unshift($table_columns, 'symbol');
        }

        $style = get_option('lcni_filter_style', []);
        $style = is_array($style) ? $style : [];

        return [
            'criteria_columns' => $criteria,
            'table_columns' => $table_columns,
            'column_labels' => $this->watchlist_service->get_column_labels($all_columns),
            'style' => [
                'font_size' => max(10, min(24, (int) ($style['font_size'] ?? 13))),
                'text_color' => sanitize_hex_color((string) ($style['text_color'] ?? '#111827')) ?: '#111827',
                'background_color' => sanitize_hex_color((string) ($style['background_color'] ?? '#ffffff')) ?: '#ffffff',
                'row_height' => max(24, min(64, (int) ($style['row_height'] ?? 36))),
            ],
            'default_filter_values' => $this->get_default_filter_values($criteria),
        ];
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
        $limit = max(10, min(200, (int) ($args['limit'] ?? 50)));
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
