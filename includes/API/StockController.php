<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_StockController {

    private $service;
    private $access_control;

    public function __construct(LCNI_StockQueryService $service, LCNI_AccessControl $access_control) {
        $this->service = $service;
        $this->access_control = $access_control;
    }

    public function registerRoutes() {
        register_rest_route('lcni/v1', '/stock', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getStockByQuery'],
            'permission_callback' => [$this->access_control, 'canAccessStocks'],
            'args' => [
                'symbol' => [
                    'required' => true,
                    'sanitize_callback' => static function ($value) {
                        return strtoupper(sanitize_text_field((string) $value));
                    },
                    'validate_callback' => static function ($value) {
                        return is_string($value) && preg_match('/^[A-Z0-9._-]{1,15}$/', strtoupper($value)) === 1;
                    },
                ],
            ],
        ]);

        register_rest_route('lcni/v1', '/stock/(?P<symbol>[A-Za-z0-9_\-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getStock'],
            'permission_callback' => [$this->access_control, 'canAccessStocks'],
        ]);

        register_rest_route('lcni/v1', '/stock/(?P<symbol>[A-Za-z0-9_\-]+)/history', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getStockHistory'],
            'permission_callback' => [$this->access_control, 'canAccessStocks'],
            'args' => [
                'limit' => [
                    'default' => 200,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route('lcni/v1', '/stocks', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getStocks'],
            'permission_callback' => [$this->access_control, 'canAccessStocks'],
            'args' => [
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);


        register_rest_route('lcni/v1', '/candles', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getCandles'],
            'permission_callback' => [$this->access_control, 'canAccessStocks'],
            'args' => [
                'symbol' => [
                    'required' => true,
                    'sanitize_callback' => static function ($value) {
                        return strtoupper(sanitize_text_field((string) $value));
                    },
                    'validate_callback' => static function ($value) {
                        return is_string($value) && preg_match('/^[A-Z0-9._-]{1,15}$/', strtoupper($value)) === 1;
                    },
                ],
                'limit' => [
                    'default' => 200,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route('lcni/v1', '/stock-signals', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getStockSignals'],
            'permission_callback' => [$this->access_control, 'canAccessStocks'],
            'args' => [
                'symbol' => [
                    'required' => true,
                    'sanitize_callback' => static function ($value) {
                        return strtoupper(sanitize_text_field((string) $value));
                    },
                    'validate_callback' => static function ($value) {
                        return is_string($value) && preg_match('/^[A-Z0-9._-]{1,15}$/', strtoupper($value)) === 1;
                    },
                ],
            ],
        ]);

        register_rest_route('lcni/v1', '/stock-overview', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getStockOverview'],
            'permission_callback' => [$this->access_control, 'canAccessStocks'],
            'args' => [
                'symbol' => [
                    'required' => true,
                    'sanitize_callback' => static function ($value) {
                        return strtoupper(sanitize_text_field((string) $value));
                    },
                    'validate_callback' => static function ($value) {
                        return is_string($value) && preg_match('/^[A-Z0-9._-]{1,15}$/', strtoupper($value)) === 1;
                    },
                ],
            ],
        ]);
    }

    public function getStockByQuery(WP_REST_Request $request) {
        $stock = $this->service->getStockDetailPage($request->get_param('symbol'));

        if (!$stock) {
            return new WP_Error('stock_not_found', 'Stock not found.', ['status' => 404]);
        }

        wp_send_json_success($stock);
    }

    public function getStock(WP_REST_Request $request) {
        $stock = $this->service->getStockDetail($request->get_param('symbol'));

        if (!$stock) {
            return new WP_Error('stock_not_found', 'Stock not found.', ['status' => 404]);
        }

        wp_send_json_success($stock);
    }

    public function getStockHistory(WP_REST_Request $request) {
        $result = $this->service->getStockHistory($request->get_param('symbol'), $request->get_param('limit'));

        if (empty($result['items'])) {
            return new WP_Error('stock_history_not_found', 'Stock history not found.', ['status' => 404]);
        }

        wp_send_json_success($result);
    }

    public function getCandles(WP_REST_Request $request) {
        $candles = $this->service->getCandles($request->get_param('symbol'), $request->get_param('limit'));

        wp_send_json_success($candles);
    }



    public function getStockSignals(WP_REST_Request $request) {
        $result = $this->service->getStockSignals($request->get_param('symbol'));

        if (!$result) {
            return new WP_Error('stock_signals_not_found', 'Stock signals not found.', ['status' => 404]);
        }

        wp_send_json_success($result);
    }

    public function getStocks(WP_REST_Request $request) {
        $result = $this->service->getStocks($request->get_param('page'), $request->get_param('per_page'));

        wp_send_json_success($result);
    }

    public function getStockOverview(WP_REST_Request $request) {
        $result = $this->service->getStockOverview($request->get_param('symbol'));

        if (!$result) {
            return new WP_Error('stock_overview_not_found', 'Stock overview not found.', ['status' => 404]);
        }

        wp_send_json_success($result);
    }
}
