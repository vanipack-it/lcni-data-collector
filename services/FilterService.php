<?php

if (!defined('ABSPATH')) {
    exit;
}

class FilterService {
    private $repository;
    private $watchlist_service;
    private $cache;

    public function __construct(SnapshotRepository $repository, LCNI_WatchlistService $watchlist_service, CacheService $cache = null) {
        $this->repository = $repository;
        $this->watchlist_service = $watchlist_service;
        $this->cache = $cache ?: new CacheService('lcni_filter');
    }

    public function getFilterResult(array $requestData): array {
        $settings = $this->getSettings();
        $all_columns = $this->watchlist_service->get_all_columns();

        $requested_columns = isset($requestData['visible_columns']) && is_array($requestData['visible_columns'])
            ? $requestData['visible_columns']
            : $settings['table_columns'];

        $columns = $this->normalizeColumns($requested_columns, $all_columns);
        if (empty($columns)) {
            $columns = $settings['table_columns'];
        }
        if (!in_array('symbol', $columns, true)) {
            array_unshift($columns, 'symbol');
        }

        $filters = $this->sanitizeFilters($requestData['filters'] ?? [], $settings['criteria_columns']);

        $page = max(1, intval($requestData['page'] ?? 1));
        $limit_input = intval($requestData['limit'] ?? 0);
        $limit = $limit_input > 0 ? max(10, min(200, $limit_input)) : 0;
        $offset = $limit > 0 ? (($page - 1) * $limit) : 0;

        $validated_data = [
            'visible_columns' => $columns,
            'filters' => $filters,
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset,
        ];

        $key = 'lcni_filter_' . md5(wp_json_encode($validated_data));
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $items = $this->repository->getFiltered($filters, $columns, $limit, $offset);
        $total = $this->repository->countFiltered($filters);

        $result = [
            'mode' => 'filter',
            'columns' => $columns,
            'column_labels' => $this->watchlist_service->get_column_labels($columns),
            'items' => $items,
            'page' => $page,
            'limit' => $limit > 0 ? $limit : count($items),
            'total' => $total,
            'has_more' => $limit > 0 ? (($offset + count($items)) < $total) : false,
        ];

        $this->cache->set($key, $result, 60);

        return $result;
    }

    private function getSettings(): array {
        $all_columns = $this->watchlist_service->get_all_columns();
        $criteria = $this->normalizeColumns(get_option('lcni_filter_criteria_columns', []), $all_columns);
        $table_columns = $this->normalizeColumns(get_option('lcni_filter_table_columns', []), $all_columns);
        $table_column_order = $this->normalizeColumns(get_option('lcni_filter_table_column_order', []), $all_columns);

        if (empty($criteria)) {
            $criteria = array_slice($all_columns, 0, 8);
        }

        if (empty($table_columns)) {
            $table_columns = $this->watchlist_service->get_default_columns('desktop');
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

        if (!in_array('symbol', $table_columns, true)) {
            array_unshift($table_columns, 'symbol');
        }

        return [
            'criteria_columns' => $criteria,
            'table_columns' => $table_columns,
        ];
    }


    public function sanitizeFiltersPublic($filters, array $allowed_columns): array {
        return $this->sanitizeFilters($filters, $allowed_columns);
    }

    private function normalizeColumns($columns, array $all_columns): array {
        $columns = is_array($columns) ? array_map('sanitize_key', $columns) : [];

        return array_values(array_filter($columns, static function ($column) use ($all_columns) {
            return in_array($column, $all_columns, true);
        }));
    }

    private function sanitizeFilters($filters, array $allowed_columns): array {
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

            $compare_value = sanitize_text_field((string) $value);
            if ($compare_value === '') {
                continue;
            }

            $normalized[] = ['column' => $column, 'operator' => $operator, 'value' => $compare_value];
        }

        return $normalized;
    }
}
