<?php

if (! defined('ABSPATH')) {
    exit;
}

class LCNI_Industry_Data
{
    /** @var wpdb */
    private $wpdb;

    /** @var array<string, array<string, string>> */
    private $metric_map = array(
        'money_flow_share' => array('table' => 'wp_lcni_industry_metrics', 'column' => 'money_flow_share'),
        'momentum' => array('table' => 'wp_lcni_industry_metrics', 'column' => 'momentum'),
        'relative_strength' => array('table' => 'wp_lcni_industry_metrics', 'column' => 'relative_strength'),
        'breadth' => array('table' => 'wp_lcni_industry_metrics', 'column' => 'breadth'),
        'industry_index' => array('table' => 'wp_lcni_industry_index', 'column' => 'industry_index'),
        'industry_return' => array('table' => 'wp_lcni_industry_return', 'column' => 'industry_return'),
        'industry_volume' => array('table' => 'wp_lcni_industry_return', 'column' => 'industry_volume'),
    );

    public function __construct($db = null)
    {
        global $wpdb;
        $this->wpdb = $db ?: $wpdb;
    }

    /**
     * @return string[]
     */
    public function get_supported_metrics()
    {
        return array_keys($this->metric_map);
    }

    /**
     * @param string $metric
     * @return array<string, string>|null
     */
    private function resolve_metric($metric)
    {
        if (! isset($this->metric_map[$metric])) {
            return null;
        }

        return $this->metric_map[$metric];
    }

    /**
     * @param string $timeframe
     * @param int    $limit
     * @return int[]
     */
    public function get_event_times($timeframe = '1D', $limit = 30)
    {
        $timeframe = sanitize_text_field($timeframe);
        $limit = max(1, min(200, absint($limit)));

        $sql = "SELECT DISTINCT event_time
                FROM wp_lcni_industry_metrics
                WHERE timeframe = %s
                ORDER BY event_time DESC
                LIMIT %d";

        $prepared = $this->wpdb->prepare($sql, $timeframe, $limit);
        $rows = $this->wpdb->get_col($prepared);

        return array_map('intval', (array) $rows);
    }

    /**
     * @param string $metric
     * @param string $timeframe
     * @param int[]  $event_times
     * @return array<int, array{industry:string, values:array<int, float|int|null>}>
     */
    public function get_metric_rows($metric, $timeframe, $event_times)
    {
        $resolved = $this->resolve_metric($metric);
        if (! $resolved) {
            return array();
        }

        $event_times = array_values(array_unique(array_map('intval', $event_times)));
        if (empty($event_times)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($event_times), '%d'));

        $sql = "SELECT src.id_icb2, icb.name_icb2, src.event_time, src.{$resolved['column']} AS metric_value
                FROM {$resolved['table']} src
                INNER JOIN wp_lcni_icb2 icb ON src.id_icb2 = icb.id_icb2
                WHERE src.timeframe = %s
                  AND src.event_time IN ($placeholders)
                ORDER BY icb.name_icb2 ASC, src.event_time DESC";

        $params = array_merge(array(sanitize_text_field($timeframe)), $event_times);
        $prepared = $this->wpdb->prepare($sql, $params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        $time_index = array_flip($event_times);
        $grouped = array();

        foreach ((array) $rows as $row) {
            $industry = isset($row['name_icb2']) ? (string) $row['name_icb2'] : '';
            $event_time = isset($row['event_time']) ? (int) $row['event_time'] : 0;
            if ($industry === '' || ! isset($time_index[$event_time])) {
                continue;
            }

            if (! isset($grouped[$industry])) {
                $grouped[$industry] = array_fill(0, count($event_times), null);
            }

            $index = $time_index[$event_time];
            $raw_value = $row['metric_value'];
            if ($raw_value === null || $raw_value === '') {
                $grouped[$industry][$index] = null;
                continue;
            }

            if ($metric === 'industry_volume') {
                $grouped[$industry][$index] = (int) $raw_value;
            } else {
                $grouped[$industry][$index] = (float) $raw_value;
            }
        }

        $result = array();
        foreach ($grouped as $industry => $values) {
            $result[] = array(
                'industry' => $industry,
                'values' => $values,
            );
        }

        return $result;
    }
}
