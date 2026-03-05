<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_IndustryRepository {

    public function get_latest_event_time($timeframe = '1D') {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_industry_metrics';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(event_time) FROM {$table} WHERE timeframe = %s",
                strtoupper((string) $timeframe)
            )
        );
    }

    public function get_heatmap($timeframe = '1D', $event_time = 0) {
        global $wpdb;

        $metrics_table = $wpdb->prefix . 'lcni_industry_metrics';
        $icb2_table = $wpdb->prefix . 'lcni_icb2';
        $resolved_event_time = (int) $event_time;

        if ($resolved_event_time <= 0) {
            $resolved_event_time = $this->get_latest_event_time($timeframe);
        }

        if ($resolved_event_time <= 0) {
            return [];
        }

        $sql = "SELECT
                    m.id_icb2,
                    COALESCE(i.name_icb2, 'Chưa phân loại') AS name_icb2,
                    m.industry_return,
                    m.industry_rating_vi,
                    m.industry_score_raw
                FROM {$metrics_table} m
                LEFT JOIN {$icb2_table} i ON i.id_icb2 = m.id_icb2
                WHERE m.timeframe = %s AND m.event_time = %d
                ORDER BY m.industry_score_raw DESC, name_icb2 ASC";

        return $wpdb->get_results(
            $wpdb->prepare($sql, strtoupper((string) $timeframe), $resolved_event_time),
            ARRAY_A
        ) ?: [];
    }

    public function get_ranking($timeframe = '1D', $event_time = 0, $limit = 30) {
        global $wpdb;

        $metrics_table = $wpdb->prefix . 'lcni_industry_metrics';
        $icb2_table = $wpdb->prefix . 'lcni_icb2';
        $resolved_event_time = (int) $event_time;

        if ($resolved_event_time <= 0) {
            $resolved_event_time = $this->get_latest_event_time($timeframe);
        }

        if ($resolved_event_time <= 0) {
            return [];
        }

        $safe_limit = max(1, min(100, (int) $limit));

        $sql = "SELECT
                    m.id_icb2,
                    COALESCE(i.name_icb2, 'Chưa phân loại') AS name_icb2,
                    m.industry_score_raw,
                    m.industry_rating_vi,
                    m.momentum,
                    m.relative_strength,
                    m.money_flow_share,
                    m.breadth,
                    m.industry_return
                FROM {$metrics_table} m
                LEFT JOIN {$icb2_table} i ON i.id_icb2 = m.id_icb2
                WHERE m.timeframe = %s AND m.event_time = %d
                ORDER BY m.industry_score_raw DESC, name_icb2 ASC
                LIMIT %d";

        return $wpdb->get_results(
            $wpdb->prepare($sql, strtoupper((string) $timeframe), $resolved_event_time, $safe_limit),
            ARRAY_A
        ) ?: [];
    }

    public function get_money_flow($timeframe = '1D', $event_time = 0) {
        global $wpdb;

        $metrics_table = $wpdb->prefix . 'lcni_industry_metrics';
        $icb2_table = $wpdb->prefix . 'lcni_icb2';
        $resolved_event_time = (int) $event_time;

        if ($resolved_event_time <= 0) {
            $resolved_event_time = $this->get_latest_event_time($timeframe);
        }

        if ($resolved_event_time <= 0) {
            return [];
        }

        $sql = "SELECT
                    m.id_icb2,
                    COALESCE(i.name_icb2, 'Chưa phân loại') AS name_icb2,
                    m.industry_value,
                    m.money_flow_share
                FROM {$metrics_table} m
                LEFT JOIN {$icb2_table} i ON i.id_icb2 = m.id_icb2
                WHERE m.timeframe = %s AND m.event_time = %d
                ORDER BY m.money_flow_share DESC, name_icb2 ASC";

        return $wpdb->get_results(
            $wpdb->prepare($sql, strtoupper((string) $timeframe), $resolved_event_time),
            ARRAY_A
        ) ?: [];
    }

    public function get_breadth($timeframe = '1D', $event_time = 0) {
        global $wpdb;

        $metrics_table = $wpdb->prefix . 'lcni_industry_metrics';
        $icb2_table = $wpdb->prefix . 'lcni_icb2';
        $resolved_event_time = (int) $event_time;

        if ($resolved_event_time <= 0) {
            $resolved_event_time = $this->get_latest_event_time($timeframe);
        }

        if ($resolved_event_time <= 0) {
            return [];
        }

        $sql = "SELECT
                    m.id_icb2,
                    COALESCE(i.name_icb2, 'Chưa phân loại') AS name_icb2,
                    m.stocks_up,
                    m.total_stocks,
                    m.breadth
                FROM {$metrics_table} m
                LEFT JOIN {$icb2_table} i ON i.id_icb2 = m.id_icb2
                WHERE m.timeframe = %s AND m.event_time = %d
                ORDER BY m.breadth DESC, name_icb2 ASC";

        return $wpdb->get_results(
            $wpdb->prepare($sql, strtoupper((string) $timeframe), $resolved_event_time),
            ARRAY_A
        ) ?: [];
    }

    public function get_index_timeseries($id_icb2, $timeframe = '1D', $limit = 120) {
        global $wpdb;

        $table = $wpdb->prefix . 'lcni_industry_index';
        $safe_limit = max(5, min(1000, (int) $limit));

        $sql = "SELECT event_time, industry_index, industry_return
                FROM {$table}
                WHERE id_icb2 = %d AND timeframe = %s
                ORDER BY event_time DESC
                LIMIT %d";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, (int) $id_icb2, strtoupper((string) $timeframe), $safe_limit),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_reverse($rows);
    }
}
