<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_IndustryAnalysisService {

    private $repository;

    public function __construct(LCNI_IndustryRepository $repository) {
        $this->repository = $repository;
    }

    public function get_dashboard_payload($timeframe = '1D', $event_time = 0, $limit = 30) {
        $tf = strtoupper((string) $timeframe);
        $latest_event_time = (int) ($event_time ?: $this->repository->get_latest_event_time($tf));

        if ($latest_event_time <= 0) {
            return [
                'event_time' => 0,
                'timeframe' => $tf,
                'heatmap' => [],
                'ranking' => [],
                'money_flow' => [],
                'breadth' => [],
            ];
        }

        return [
            'event_time' => $latest_event_time,
            'timeframe' => $tf,
            'heatmap' => $this->repository->get_heatmap($tf, $latest_event_time),
            'ranking' => $this->repository->get_ranking($tf, $latest_event_time, $limit),
            'money_flow' => $this->repository->get_money_flow($tf, $latest_event_time),
            'breadth' => $this->repository->get_breadth($tf, $latest_event_time),
        ];
    }

    public function get_index_series($id_icb2, $timeframe = '1D', $limit = 120) {
        return $this->repository->get_index_timeseries((int) $id_icb2, $timeframe, $limit);
    }
}
