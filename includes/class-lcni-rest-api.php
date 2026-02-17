<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Rest_API {

    private $stock_controller;

    public function __construct() {
        $access_control = new LCNI_AccessControl();
        $repository = new LCNI_Data_StockRepository();
        $indicator_service = new LCNI_IndicatorService();
        $cache = new LCNI_CacheService('lcni_rest_api', 60);
        $stock_service = new LCNI_StockQueryService($repository, $indicator_service, $access_control, $cache);

        $this->stock_controller = new LCNI_StockController($stock_service, $access_control);

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/dashboard', [
            'methods' => 'GET',
            'callback' => [$this, 'get_dashboard'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lcni/v1', '/screener', [
            'methods' => 'GET',
            'callback' => [$this, 'get_screener'],
            'permission_callback' => '__return_true',
        ]);

        $this->stock_controller->registerRoutes();

        register_rest_route('lcni/v1', '/user/package', [
            'methods' => 'GET',
            'callback' => [$this, 'get_user_package'],
            'permission_callback' => static function () {
                return is_user_logged_in();
            },
        ]);

        register_rest_route('lcni/v1', '/watchlist', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_watchlist'],
                'permission_callback' => static function () {
                    return is_user_logged_in();
                },
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'add_watchlist_symbol'],
                'permission_callback' => static function () {
                    return is_user_logged_in();
                },
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'remove_watchlist_symbol'],
                'permission_callback' => static function () {
                    return is_user_logged_in();
                },
            ],
        ]);
    }

    public function get_dashboard() {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_ohlc';
        $latest_event_time = (int) $wpdb->get_var("SELECT MAX(event_time) FROM {$table} WHERE timeframe='1D'");

        $top_rs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT symbol, close_price, pct_1m, pct_3m, pct_6m, pct_1y
                FROM {$table}
                WHERE timeframe = '1D' AND event_time = %d
                ORDER BY pct_1m DESC
                LIMIT 10",
                $latest_event_time
            ),
            ARRAY_A
        );

        $market_overview = [
            'advancers' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timeframe='1D' AND event_time=%d AND pct_t_1 > 0", $latest_event_time)),
            'decliners' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timeframe='1D' AND event_time=%d AND pct_t_1 < 0", $latest_event_time)),
            'unchanged' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE timeframe='1D' AND event_time=%d AND (pct_t_1 = 0 OR pct_t_1 IS NULL)", $latest_event_time)),
        ];

        return rest_ensure_response([
            'top_rs' => $top_rs,
            'rada' => [
                'updated_at' => $latest_event_time,
                'total_symbols' => array_sum($market_overview),
            ],
            'market_overview' => $market_overview,
        ]);
    }

    public function get_screener(WP_REST_Request $request) {
        global $wpdb;

        $page = max(1, (int) $request->get_param('page'));
        $per_page = max(1, min(100, (int) ($request->get_param('per_page') ?: 20)));
        $offset = ($page - 1) * $per_page;
        $min_price = (float) ($request->get_param('min_price') ?: 0);
        $market_id = sanitize_text_field((string) $request->get_param('market_id'));

        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $symbol_table = $wpdb->prefix . 'lcni_symbols';
        $latest_event_time = (int) $wpdb->get_var("SELECT MAX(event_time) FROM {$ohlc_table} WHERE timeframe='1D'");

        $where = ["o.timeframe = '1D'", $wpdb->prepare('o.event_time = %d', $latest_event_time), $wpdb->prepare('o.close_price >= %f', $min_price)];
        if ($market_id !== '') {
            $where[] = $wpdb->prepare('s.market_id = %s', $market_id);
        }
        $where_sql = implode(' AND ', $where);

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ohlc_table} o INNER JOIN {$symbol_table} s ON s.symbol=o.symbol WHERE {$where_sql}");
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.symbol, s.market_id, o.close_price, o.volume, o.pct_t_1, o.pct_1m, o.rsi
                FROM {$ohlc_table} o
                INNER JOIN {$symbol_table} s ON s.symbol = o.symbol
                WHERE {$where_sql}
                ORDER BY o.value_traded DESC
                LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        return rest_ensure_response([
            'filters' => [
                'min_price' => $min_price,
                'market_id' => $market_id,
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
            'items' => $rows,
        ]);
    }

    public function get_user_package() {
        $user_id = get_current_user_id();
        $package = get_user_meta($user_id, 'lcni_user_package', true);

        return rest_ensure_response([
            'package' => $package ?: 'free',
            'features' => [
                'dashboard' => true,
                'screener' => true,
                'stock_detail' => true,
                'watchlist' => $package !== 'free',
            ],
        ]);
    }

    public function get_watchlist() {
        return rest_ensure_response([
            'symbols' => $this->read_watchlist(),
        ]);
    }

    public function add_watchlist_symbol(WP_REST_Request $request) {
        $symbol = strtoupper(sanitize_text_field((string) $request->get_param('symbol')));
        if ($symbol === '') {
            return new WP_Error('invalid_symbol', 'Symbol không hợp lệ.', ['status' => 400]);
        }

        $watchlist = $this->read_watchlist();
        if (!in_array($symbol, $watchlist, true)) {
            $watchlist[] = $symbol;
        }

        $this->save_watchlist($watchlist);

        return rest_ensure_response(['symbols' => $watchlist]);
    }

    public function remove_watchlist_symbol(WP_REST_Request $request) {
        $symbol = strtoupper(sanitize_text_field((string) $request->get_param('symbol')));
        $watchlist = array_values(array_filter(
            $this->read_watchlist(),
            static function ($item) use ($symbol) {
                return $item !== $symbol;
            }
        ));

        $this->save_watchlist($watchlist);

        return rest_ensure_response(['symbols' => $watchlist]);
    }

    private function read_watchlist() {
        $user_id = get_current_user_id();
        $watchlist = get_user_meta($user_id, 'lcni_watchlist', true);

        return is_array($watchlist) ? array_values(array_unique(array_map('strtoupper', $watchlist))) : [];
    }

    private function save_watchlist($watchlist) {
        update_user_meta(get_current_user_id(), 'lcni_watchlist', array_values(array_unique($watchlist)));
    }
}
