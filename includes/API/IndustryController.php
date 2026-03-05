<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_IndustryController {

    private $service;

    public function __construct(LCNI_IndustryAnalysisService $service) {
        $this->service = $service;
    }

    public function register_routes() {
        register_rest_route('lcni/v1', '/industry/dashboard', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_dashboard'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lcni/v1', '/industry/ranking', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_ranking'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('lcni/v1', '/industry/index/(?P<id_icb2>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_index_series'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function get_dashboard(WP_REST_Request $request) {
        $timeframe = strtoupper((string) $request->get_param('timeframe'));
        if ($timeframe === '') {
            $timeframe = '1D';
        }

        $event_time = (int) $request->get_param('event_time');
        $limit = (int) ($request->get_param('limit') ?: 30);

        return rest_ensure_response([
            'success' => true,
            'data' => $this->service->get_dashboard_payload($timeframe, $event_time, $limit),
        ]);
    }

    public function get_ranking(WP_REST_Request $request) {
        $timeframe = strtoupper((string) $request->get_param('timeframe'));
        if ($timeframe === '') {
            $timeframe = '1D';
        }

        $event_time = (int) $request->get_param('event_time');
        $limit = (int) ($request->get_param('limit') ?: 30);

        $payload = $this->service->get_dashboard_payload($timeframe, $event_time, $limit);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'event_time' => $payload['event_time'],
                'timeframe' => $payload['timeframe'],
                'items' => $payload['ranking'],
            ],
        ]);
    }

    public function get_index_series(WP_REST_Request $request) {
        $id_icb2 = (int) $request->get_param('id_icb2');
        $timeframe = strtoupper((string) $request->get_param('timeframe'));
        if ($timeframe === '') {
            $timeframe = '1D';
        }

        $limit = (int) ($request->get_param('limit') ?: 120);

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'id_icb2' => $id_icb2,
                'timeframe' => $timeframe,
                'series' => $this->service->get_index_series($id_icb2, $timeframe, $limit),
            ],
        ]);
    }
}
