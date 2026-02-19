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

        register_rest_route('lcni/v1', '/watchlist/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_watchlist_settings'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lcni/v1', '/watchlist/preferences', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_watchlist_preferences'],
                'permission_callback' => static function () {
                    return is_user_logged_in();
                },
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_watchlist_preferences'],
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
        return rest_ensure_response($this->resolve_user_package(get_current_user_id()));
    }

    public function get_watchlist_settings() {
        $allowed_fields = $this->get_watchlist_allowed_fields();

        return rest_ensure_response([
            'allowed_fields' => $allowed_fields,
            'labels' => $this->get_watchlist_labels($allowed_fields),
            'styles' => $this->get_watchlist_styles(),
        ]);
    }

    public function get_watchlist_preferences() {
        $user_id = get_current_user_id();
        $allowed_fields = $this->get_watchlist_allowed_fields();
        $saved = get_user_meta($user_id, 'lcni_watchlist_preferences', true);
        $selected_fields = is_array($saved) && isset($saved['selected_fields']) && is_array($saved['selected_fields'])
            ? array_values(array_intersect($allowed_fields, array_map('sanitize_key', $saved['selected_fields'])))
            : [];

        if (empty($selected_fields)) {
            $selected_fields = $allowed_fields;
        }

        return rest_ensure_response([
            'selected_fields' => $selected_fields,
            'allowed_fields' => $allowed_fields,
        ]);
    }

    public function save_watchlist_preferences(WP_REST_Request $request) {
        $allowed_fields = $this->get_watchlist_allowed_fields();
        $selected_fields = $request->get_param('selected_fields');
        $selected_fields = is_array($selected_fields) ? array_values(array_intersect($allowed_fields, array_map('sanitize_key', $selected_fields))) : [];

        if (empty($selected_fields)) {
            $selected_fields = $allowed_fields;
        }

        update_user_meta(get_current_user_id(), 'lcni_watchlist_preferences', [
            'selected_fields' => $selected_fields,
        ]);

        return rest_ensure_response([
            'selected_fields' => $selected_fields,
        ]);
    }

    public function get_watchlist() {
        $package = $this->resolve_user_package(get_current_user_id());
        if (empty($package['features']['watchlist'])) {
            return new WP_Error('watchlist_requires_premium', 'Tính năng Watchlist chỉ dành cho gói Premium.', ['status' => 403]);
        }

        $symbols = $this->read_watchlist();

        return rest_ensure_response([
            'symbols' => $symbols,
            'items' => $this->build_watchlist_rows($symbols),
        ]);
    }

    public function add_watchlist_symbol(WP_REST_Request $request) {
        $package = $this->resolve_user_package(get_current_user_id());
        if (empty($package['features']['watchlist'])) {
            return new WP_Error('watchlist_requires_premium', 'Tính năng Watchlist chỉ dành cho gói Premium.', ['status' => 403]);
        }

        $symbol = strtoupper(sanitize_text_field((string) $request->get_param('symbol')));
        if (!preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol)) {
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
        $package = $this->resolve_user_package(get_current_user_id());
        if (empty($package['features']['watchlist'])) {
            return new WP_Error('watchlist_requires_premium', 'Tính năng Watchlist chỉ dành cho gói Premium.', ['status' => 403]);
        }

        $symbol = strtoupper(sanitize_text_field((string) $request->get_param('symbol')));
        if (!preg_match('/^[A-Z0-9._-]{1,15}$/', $symbol)) {
            return new WP_Error('invalid_symbol', 'Symbol không hợp lệ.', ['status' => 400]);
        }

        $watchlist = array_values(array_filter(
            $this->read_watchlist(),
            static function ($item) use ($symbol) {
                return $item !== $symbol;
            }
        ));

        $this->save_watchlist($watchlist);

        return rest_ensure_response(['symbols' => $watchlist]);
    }

    private function resolve_user_package($user_id) {
        $package = strtolower((string) get_user_meta($user_id, 'lcni_user_package', true));
        if ($package === 'pro') {
            $package = 'premium';
        }
        $package = in_array($package, ['free', 'premium'], true) ? $package : 'free';

        $package_settings = get_option('lcni_saas_package_features', []);
        $default_by_package = [
            'free' => [
                'dashboard' => true,
                'screener' => true,
                'stock_detail' => true,
                'watchlist' => false,
            ],
            'premium' => [
                'dashboard' => true,
                'screener' => true,
                'stock_detail' => true,
                'watchlist' => true,
            ],
        ];
        $default_features = $default_by_package[$package] ?? $default_by_package['free'];
        $features = isset($package_settings[$package]) && is_array($package_settings[$package])
            ? wp_parse_args(array_map('rest_sanitize_boolean', $package_settings[$package]), $default_features)
            : $default_features;

        return [
            'package' => $package,
            'features' => $features,
        ];
    }

    private function read_watchlist() {
        $user_id = get_current_user_id();
        $watchlist = get_user_meta($user_id, 'lcni_watchlist', true);

        return is_array($watchlist) ? array_values(array_unique(array_map('strtoupper', $watchlist))) : [];
    }

    private function save_watchlist($watchlist) {
        update_user_meta(get_current_user_id(), 'lcni_watchlist', array_values(array_unique($watchlist)));
    }

    private function get_watchlist_allowed_fields() {
        $settings = get_option('lcni_frontend_settings_watchlist', []);
        $allowed = isset($settings['allowed_fields']) && is_array($settings['allowed_fields']) ? array_values(array_map('sanitize_key', $settings['allowed_fields'])) : [];

        if (empty($allowed)) {
            $allowed = ['symbol', 'exchange', 'icb2_name', 'close_price', 'pct_t_1', 'volume', 'value_traded', 'xay_nen', 'xay_nen_count_30', 'nen_type', 'pha_nen', 'tang_gia_kem_vol', 'smart_money', 'rs_exchange_status', 'rs_exchange_recommend', 'rsi', 'macd', 'macd_signal', 'event_time'];
        }

        return $allowed;
    }

    private function get_watchlist_styles() {
        $settings = get_option('lcni_frontend_settings_watchlist', []);

        return isset($settings['styles']) && is_array($settings['styles']) ? $settings['styles'] : [];
    }

    private function get_watchlist_labels(array $allowed_fields) {
        $labels = [
            'symbol' => 'Mã CK',
            'exchange' => 'Sàn',
            'icb2_name' => 'Ngành ICB 2',
            'close_price' => 'Giá đóng cửa gần nhất',
            'pct_t_1' => '% T-1',
            'volume' => 'Khối lượng',
            'value_traded' => 'Giá trị giao dịch',
            'xay_nen' => 'Nền giá',
            'xay_nen_count_30' => 'Số phiên đi nền trong 30 phiên',
            'nen_type' => 'Dạng nền',
            'pha_nen' => 'Tín hiệu phá nền',
            'tang_gia_kem_vol' => 'Tăng giá kèm Vol',
            'smart_money' => 'Tín hiệu smart',
            'rs_exchange_status' => 'Trạng thái RS',
            'rs_exchange_recommend' => 'Khuyến nghị RS',
            'rsi' => 'RSI',
            'macd' => 'MACD',
            'macd_signal' => 'MACD Signal',
            'event_time' => 'Ngày dữ liệu gần nhất',
        ];

        $resolved = [];
        foreach ($allowed_fields as $field) {
            $resolved[$field] = $labels[$field] ?? strtoupper($field);
        }

        return $resolved;
    }

    private function build_watchlist_rows(array $symbols) {
        if (empty($symbols)) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($symbols), '%s'));
        $ohlc_table = $wpdb->prefix . 'lcni_ohlc';
        $latest_event_time = (int) $wpdb->get_var("SELECT MAX(event_time) FROM {$ohlc_table} WHERE timeframe='1D'");

        if ($latest_event_time <= 0) {
            return array_map(static function ($symbol) {
                return ['symbol' => $symbol];
            }, $symbols);
        }

        $query = $wpdb->prepare(
            "SELECT symbol, close_price, pct_t_1, volume, value_traded, rs_exchange_status, rs_exchange_recommend, rsi, macd, macd_signal, event_time
            FROM {$ohlc_table}
            WHERE timeframe='1D' AND event_time=%d AND symbol IN ({$placeholders})",
            array_merge([$latest_event_time], $symbols)
        );

        $rows = $wpdb->get_results($query, ARRAY_A);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[strtoupper((string) $row['symbol'])] = $row;
        }

        $output = [];
        foreach ($symbols as $symbol) {
            $key = strtoupper($symbol);
            $output[] = isset($indexed[$key]) ? $indexed[$key] : ['symbol' => $key];
        }

        return $output;
    }
}
