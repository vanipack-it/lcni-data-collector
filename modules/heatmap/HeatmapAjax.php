<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Heatmap_Ajax {

    private $repository;
    private $watchlist_service;

    public function __construct(SnapshotRepository $repository, LCNI_WatchlistService $watchlist_service) {
        $this->repository        = $repository;
        $this->watchlist_service = $watchlist_service;
    }

    /**
     * Kiểm tra quyền xem heatmap.
     * Ưu tiên SaasService nếu có; fallback cho phép tất cả (PermissionMiddleware lo REST guard).
     */
    public function check_view_permission(): bool {
        if ( class_exists( 'LCNI_SaaS_Service' ) && class_exists( 'LCNI_SaaS_Repository' ) ) {
            static $svc = null;
            if ( $svc === null ) {
                $svc = new LCNI_SaaS_Service( new LCNI_SaaS_Repository() );
            }
            return $svc->can( 'heatmap', 'view' );
        }
        return true; // SaaS module chưa active
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/heatmap/data', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_data'],
            'permission_callback' => [$this, 'check_view_permission'],
        ]);
        // Debug endpoint — admin only
        register_rest_route('lcni/v1', '/heatmap/debug', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_debug'],
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ]);
    }

    public function get_data(WP_REST_Request $request) {
        $cells = $this->get_cells_config();
        if (empty($cells)) {
            return rest_ensure_response(['tiles' => []]);
        }

        $tiles = [];
        foreach ($cells as $cell) {
            $column = sanitize_key($cell['column'] ?? '');
            if ($column === '') continue;

            $filter  = $this->build_cell_filter($cell);
            $symbols = $this->get_symbols_for_filter($filter);

            $tiles[] = [
                'column'     => $column,
                'label'      => sanitize_text_field($cell['label'] ?? $column),
                'color'      => sanitize_hex_color($cell['color']      ?? '#dc2626') ?: '#dc2626',
                'text_color' => sanitize_hex_color($cell['text_color'] ?? '#ffffff') ?: '#ffffff',
                'count'      => count($symbols),
                'symbols'    => $symbols,
                // filter data để JS build URL mở trang filter với bộ lọc đúng
                'filter'     => $filter,
            ];
        }

        return rest_ensure_response(['tiles' => $tiles]);
    }

    /**
     * Debug endpoint: /wp-json/lcni/v1/heatmap/debug (admin only)
     * Shows stored cells, column map, and per-cell query results.
     */
    public function get_debug(WP_REST_Request $request) {
        global $wpdb;

        $cells        = $this->get_cells_config();
        $all_cols     = $this->watchlist_service->get_all_columns();
        $col_labels   = $this->watchlist_service->get_column_labels($all_cols);
        $ohlc_table   = $wpdb->prefix . 'lcni_ohlc_latest';

        // Show SHOW COLUMNS from ohlc_latest to compare
        $ohlc_cols = $wpdb->get_col("SHOW COLUMNS FROM {$ohlc_table}", 0);

        $debug_cells = [];
        foreach ($cells as $cell) {
            $column = sanitize_key($cell['column'] ?? '');
            $filter = $this->build_cell_filter($cell);

            // Raw query on ohlc_latest only for simplicity
            $raw_val = esc_sql($cell['value'] ?? '');
            $raw_col = esc_sql($column);
            $direct_count = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$ohlc_table} WHERE `{$raw_col}` = %s", $cell['value'] ?? '')
            );
            $direct_sample = $wpdb->get_col(
                $wpdb->prepare("SELECT `symbol` FROM {$ohlc_table} WHERE `{$raw_col}` = %s LIMIT 5", $cell['value'] ?? '')
            );
            $distinct_vals = $wpdb->get_col(
                "SELECT DISTINCT `{$raw_col}` FROM {$ohlc_table} WHERE `{$raw_col}` IS NOT NULL AND `{$raw_col}` <> '' LIMIT 10"
            );

            // Via repository
            $repo_rows   = $this->repository->getFiltered($filter, ['symbol'], 0, 0);
            $repo_count  = count($repo_rows);

            $debug_cells[] = [
                'stored_cell'       => $cell,
                'built_filter'      => $filter,
                'col_in_ohlc'       => in_array($column, (array) $ohlc_cols, true),
                'direct_count'      => (int) $direct_count,
                'direct_sample'     => $direct_sample,
                'distinct_values'   => $distinct_vals,
                'repo_count'        => $repo_count,
                'repo_sample'       => array_slice(array_column($repo_rows, 'symbol'), 0, 5),
                'last_wpdb_query'   => $wpdb->last_query,
                'last_wpdb_error'   => $wpdb->last_error,
            ];
        }

        return rest_ensure_response([
            'stored_cells'      => $cells,
            'all_columns_count' => count($all_cols),
            'ohlc_columns'      => $ohlc_cols,
            'debug_cells'       => $debug_cells,
        ]);
    }

    // ─── private helpers ─────────────────────────────────────────────────────

    private function get_cells_config(): array {
        $raw = get_option('lcni_heatmap_cells', []);
        return is_array($raw) ? array_values($raw) : [];
    }

    private function build_cell_filter(array $cell): array {
        $column   = sanitize_key($cell['column'] ?? '');
        $operator = in_array($cell['operator'] ?? '', ['=', '!=', '>', '>=', '<', '<=', 'between', 'contains', 'not_contains', 'in'], true)
            ? $cell['operator'] : '!=';
        $value  = $cell['value']  ?? '';
        $value2 = $cell['value2'] ?? '';

        if ($operator === 'between') {
            return [['column' => $column, 'operator' => 'between', 'value' => [$value, $value2]]];
        }
        if ($operator === 'in') {
            $items = is_array($value)
                ? $value
                : array_filter(array_map('trim', explode(',', (string) $value)));
            return [['column' => $column, 'operator' => 'in', 'value' => array_values($items)]];
        }
        if ((string) $value === '' && !in_array($operator, ['contains', 'not_contains'], true)) {
            return [['column' => $column, 'operator' => '!=', 'value' => '']];
        }
        return [['column' => $column, 'operator' => $operator, 'value' => $value]];
    }

    private function get_symbols_for_filter(array $filters): array {
        $rows = $this->repository->getFiltered($filters, ['symbol'], 0, 0);
        return array_values(array_filter(array_column($rows, 'symbol')));
    }
}
