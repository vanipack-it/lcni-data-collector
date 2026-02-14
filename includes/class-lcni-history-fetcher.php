<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_HistoryFetcher {

    const DEFAULT_LIMIT = 5000;

    public static function fetch($symbol, $timeframe, $to, $limit = self::DEFAULT_LIMIT, $min_from = 1) {
        $limit = max(1, (int) $limit);
        $to = max(1, (int) $to);
        $min_from = max(1, (int) $min_from);
        $interval = self::timeframe_to_seconds($timeframe);
        $from = max($min_from, $to - ($interval * $limit));

        $payload = LCNI_API::get_candles_by_range($symbol, $timeframe, $from, $to);
        if (!is_array($payload)) {
            return new WP_Error('fetch_failed', LCNI_API::get_last_request_error());
        }

        $rows = lcni_convert_candles($payload, $symbol, $timeframe);
        if (empty($rows)) {
            return [
                'rows' => [],
                'oldest_event_time' => 0,
            ];
        }

        $timestamps = [];
        foreach ($rows as $row) {
            $event_time = strtotime($row['candle_time']);
            if ($event_time !== false && $event_time > 0 && $event_time <= $to) {
                $timestamps[] = $event_time;
            }
        }

        if (empty($timestamps)) {
            return [
                'rows' => [],
                'oldest_event_time' => 0,
            ];
        }

        return [
            'rows' => $rows,
            'oldest_event_time' => min($timestamps),
        ];
    }

    public static function timeframe_to_seconds($timeframe) {
        $value = strtoupper(trim((string) $timeframe));

        if (preg_match('/^(\d+)M$/', $value, $matches)) {
            return max(60, ((int) $matches[1]) * 60);
        }

        if (preg_match('/^(\d+)H$/', $value, $matches)) {
            return max(HOUR_IN_SECONDS, ((int) $matches[1]) * HOUR_IN_SECONDS);
        }

        if (preg_match('/^(\d+)D$/', $value, $matches)) {
            return max(DAY_IN_SECONDS, ((int) $matches[1]) * DAY_IN_SECONDS);
        }

        if (preg_match('/^\d+$/', $value)) {
            return max(60, ((int) $value) * 60);
        }

        return DAY_IN_SECONDS;
    }
}
