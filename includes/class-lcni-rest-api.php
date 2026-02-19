<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Rest_API {

    private $stock_controller;
    private $access_control;

    public function __construct() {
        $access_control = new LCNI_AccessControl();
        $this->access_control = $access_control;
        $repository = new LCNI_Data_StockRepository();
        $indicator_service = new LCNI_IndicatorService();
        $cache = new LCNI_CacheService('lcni_rest_api', 60);
        $stock_service = new LCNI_StockQueryService($repository, $indicator_service, $access_control, $cache);

        $this->stock_controller = new LCNI_StockController($stock_service, $access_control);

        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('rest_pre_serve_request', [$this, 'set_no_cache_headers'], 10, 4);
    }


    public function set_no_cache_headers($served, $result, $request, $server) {
        if (!($request instanceof WP_REST_Request)) {
            return $served;
        }

        $route = (string) $request->get_route();
        if (strpos($route, '/lcni/v1/') === 0) {
            header('Cache-Control: no-cache, no-store, must-revalidate');
        }

        return $served;
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

    private function get_feature_matrix() {
        $defaults = [
            'free' => [
                'dashboard' => true,
                'screener' => true,
                'stock_detail' => true,
                'chart' => true,
                'watchlist' => false,
                'shortcode_add_watchlist' => false,
                'history_extended' => false,
            ],
            'premium' => [
                'dashboard' => true,
                'screener' => true,
                'stock_detail' => true,
                'chart' => true,
                'watchlist' => true,
                'shortcode_add_watchlist' => true,
                'history_extended' => true,
            ],
        ];

        $saved = get_option('lcni_saas_feature_matrix', []);
        if (!is_array($saved)) {
            return $defaults;
        }

        foreach (['free', 'premium'] as $package) {
            if (!isset($saved[$package]) || !is_array($saved[$package])) {
                $saved[$package] = [];
            }

            foreach ($defaults[$package] as $feature => $value) {
                $saved[$package][$feature] = array_key_exists($feature, $saved[$package]) ? !empty($saved[$package][$feature]) : $value;
            }
        }

        return $saved;
    }

    private function get_effective_user_features() {
        $package = $this->access_control->resolvePackage();
        $matrix = $this->get_feature_matrix();
        $features = $matrix[$package] ?? $matrix['free'];

        return [
            'package' => $package,
            'features' => $features,
        ];
    }

    public function get_user_package() {
        $data = $this->get_effective_user_features();

        return rest_ensure_response([
            'package' => $data['package'],
            'features' => $data['features'],
        ]);
    }

    public function get_watchlist() {
        $user_features = $this->get_effective_user_features();
        if (empty($user_features['features']['watchlist'])) {
            return new WP_Error('forbidden_watchlist', 'Gói hiện tại chưa có quyền Watchlist.', ['status' => 403]);
        }

        return rest_ensure_response([
            'symbols' => $this->read_watchlist(),
        ]);
    }

    public function add_watchlist_symbol(WP_REST_Request $request) {
        $user_features = $this->get_effective_user_features();
        if (empty($user_features['features']['watchlist']) || empty($user_features['features']['shortcode_add_watchlist'])) {
            return new WP_Error('forbidden_watchlist', 'Gói hiện tại chưa có quyền thêm Watchlist.', ['status' => 403]);
        }

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
        $user_features = $this->get_effective_user_features();
        if (empty($user_features['features']['watchlist'])) {
            return new WP_Error('forbidden_watchlist', 'Gói hiện tại chưa có quyền Watchlist.', ['status' => 403]);
        }

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
